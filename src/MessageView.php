<?php

namespace GlpiPlugin\Mailtoticket;

use Glpi\RichText\RichText;
use Html;
use ITILCategory;
use Laminas\Mail\Storage\Message;
use Ticket;
use User;

/**
 * Dados da tela de um e-mail (usados na página inteira e no painel de leitura).
 */
final class MessageView
{
    /** @return array<string,mixed> contexto para templates/message.html.twig */
    public static function context(Mailbox $mailbox, Client $client, string $uid, Message $message): array
    {
        global $DB;

        $mailbox_id = (int) $mailbox->getID();
        $base       = PLUGIN_MAILTOTICKET_WEBDIR . '/front';
        $self       = $base . '/message.php?mailbox=' . $mailbox_id . '&uid=' . rawurlencode($uid);
        $back       = $base . '/messages.php?mailbox=' . $mailbox_id;

        $body        = $client->collectorShell()->getBody($message);
        $attachments = Client::attachmentNames($message);
        $client->restoreUnseen();
        $is_html   = (bool) preg_match('/<\s*(html|body|div|p|br|table|span|a|img)\b/i', $body);
        $body_html = $is_html ? RichText::getSafeHtml($body) : nl2br(htmlescape($body), false);

        $key    = Client::messageKey($message);
        $state  = MailTicket::getState($mailbox_id, $key);
        $linked = ($state !== null && $state['status'] !== MailTicket::STATUS_DISCARDED) ? $state : null;

        // Remetente: é usuário do GLPI? (apenas consulta; o plugin nunca cria usuário)
        $sender_email = Client::senderEmail($message);
        $sender_user  = null;
        if ($sender_email !== null) {
            $u = $DB->request([
                'SELECT'    => ['glpi_useremails.users_id AS id'],
                'FROM'      => 'glpi_useremails',
                'LEFT JOIN' => ['glpi_users' => ['FKEY' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']]],
                'WHERE'     => ['glpi_useremails.email' => $sender_email],
                'ORDER'     => ['glpi_users.is_active DESC', 'glpi_users.is_deleted ASC'],
                'LIMIT'     => 1,
            ])->current();
            if ($u) {
                $usr = new User();
                if ($usr->getFromDB((int) $u['id'])) {
                    $sender_user = [
                        'id'     => (int) $usr->getID(),
                        'name'   => trim($usr->fields['firstname'] . ' ' . $usr->fields['realname']) ?: $usr->fields['name'],
                        'login'  => $usr->fields['name'],
                        'active' => (bool) $usr->fields['is_active'],
                        'url'    => User::getFormURLWithID($usr->getID()),
                    ];
                }
            }
        }

        // Chamado ao qual este e-mail responde (se houver)
        $related = null;
        if ($linked === null && $state === null) {
            $found = TicketCreator::relatedTicket($mailbox, $client, $message);
            if ($found !== null) {
                $t       = $found['ticket'];
                $related = [
                    'via'    => $found['via'],
                    'id'     => (int) $t->getID(),
                    'name'   => $t->fields['name'],
                    'status' => Ticket::getStatus((int) $t->fields['status']),
                    'closed' => (int) $t->fields['status'] === Ticket::CLOSED,
                    'url'    => Ticket::getFormURLWithID($t->getID()),
                    'can_followup' => $t->canAddFollowups(),
                    'sender_role'  => TicketCreator::senderRole($t, (int) ($sender_user['id'] ?? 0), $sender_email),
                    'can_observer' => $t->canUpdateItem(),
                ];
            }
        }

        $category_html = '';
        $js            = '';
        if ($state === null) {
            $entity      = (int) $mailbox->fields['entities_id'];
            $rand        = mt_rand();
            $default_cat = $mailbox->getTicketDefaults()['itilcategories_id'];
            $category_html = ITILCategory::dropdown([
                'name'        => 'itilcategories_id',
                'value'       => $default_cat,
                'rand'        => $rand,
                'display'     => false,
                'entity'      => $entity,
                'entity_sons' => true,
                'width'       => '100%',
            ]);
            $names = [];
            foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories']) as $row) {
                $names[(int) $row['id']] = $row['completename'];
            }
            $js = self::script($rand, $names, Client::subject($message), $sender_user['name'] ?? ($sender_email ?? ''), (bool) $sender_user, \Dropdown::getDropdownName('glpi_entities', $entity), $related);
        }

        return [
            'mailbox_name'  => $mailbox->fields['name'],
            'subject'       => Client::subject($message),
            'from'          => Client::from($message),
            'to'            => Client::addresses($message, 'to'),
            'cc'            => Client::addresses($message, 'cc'),
            'date'          => ($ts = Client::date($message)) ? date('Y-m-d H:i:s', $ts) : '',
            'body_html'     => $body_html,
            'attachments'   => $attachments,
            'tickets_id'    => $linked['tickets_id'] ?? null,
            'is_followup'   => $linked !== null && $linked['status'] === MailTicket::STATUS_FOLLOWUP,
            'discarded'     => $state !== null && $state['status'] === MailTicket::STATUS_DISCARDED,
            'related'       => $related,
            'category_html' => $category_html,
            'js'            => $js,
            'default_title' => Client::subject($message),
            'can_create'    => Rights::canOpenTicket(),
            'can_discard'   => Rights::canDiscard(),
            'key'           => $key,
            'sender_email'  => $sender_email,
            'sender_user'   => $sender_user,
            'self'          => $self,
            'back'          => $back,
            'mailbox_id'    => $mailbox_id,
            'uid'           => $uid,
            'ticket_url'    => Ticket::getFormURL(),
            'base'          => $base,
        ];
    }

    /** JS do formulário: título x categoria, modo (acompanhamento/novo) e resumo do que será criado. */
    private static function script(int $rand, array $names, string $subject, string $requester, bool $is_user, string $entity, ?array $related): string
    {
        $j = static fn ($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
        $names_json = $j($names);
        $subject_j  = $j($subject);
        $req_j      = $j($requester);
        $entity_j   = $j($entity);
        $related_j  = $j($related ? ['id' => $related['id'], 'name' => $related['name']] : null);
        $is_user_j  = $is_user ? 'true' : 'false';

        return Html::scriptBlock("
            $(function () {
                const names = {$names_json}, subject = {$subject_j}, requester = {$req_j},
                      entity = {$entity_j}, related = {$related_j}, isUser = {$is_user_j};
                const sel = $('#dropdown_itilcategories_id{$rand}'), title = $('#mtt_title'),
                      useCat = $('#mtt_use_category'), useReq = $('#mtt_requester'),
                      preview = $('#mtt_preview'), modeBox = $('#mtt_new_fields');
                let edited = false;

                const mode = () => $('input[name=mode]:checked').val() || 'new';
                // destaca visualmente a opção escolhida
                const hasFollowupBox = $('#mtt_followup_opts').length > 0;
                function esc(t) { return $('<div>').text(t).html(); }

                function applyTitle() {
                    title.val(useCat.is(':checked') && sel.val() > 0 ? (names[sel.val()] || subject) : subject);
                }
                function render() {
                    const followup = mode() === 'followup';
                    modeBox.toggle(!followup);
                    $('#mtt_followup_opts').toggle(followup && hasFollowupBox);
                    $('#mtt_submit_new').toggle(!followup);
                    $('#mtt_submit_followup').toggle(followup);
                    let h;
                    if (followup) {
                        h = '<i class=\"ti ti-message-plus me-1\"></i>Será adicionado como <b>acompanhamento</b> do chamado <b>#' + related.id + '</b> (' + esc(related.name) + ').'
                          + ($('input[name=add_observer]').is(':checked') ? '<br>O remetente (' + esc(requester) + ') será adicionado como <b>observador</b>.' : '');
                    } else {
                        const cat = sel.val() > 0 ? esc(names[sel.val()] || '') : '<span class=\"text-danger\">escolha a categoria</span>';
                        const req = useReq.is(':checked')
                            ? esc(requester) + (isUser ? '' : ' <span class=\"text-muted\">(só e-mail)</span>')
                            : '<span class=\"text-muted\">nenhum (o remetente não será requerente)</span>';
                        h = '<i class=\"ti ti-ticket me-1\"></i>Será criado: <b>' + esc(title.val() || subject) + '</b><br>'
                          + 'Categoria: ' + cat + ' &middot; Requerente: ' + req + ' &middot; Entidade: ' + esc(entity);
                    }
                    preview.html(h);
                }

                title.on('input', function () { edited = title.val() !== ''; render(); });
                sel.on('change', function () { if (!edited) { applyTitle(); } render(); });
                useCat.on('change', function () { edited = false; applyTitle(); render(); });
                useReq.on('change', render);
                $('input[name=mode]').on('change', render);
                $('input[name=add_observer]').on('change', render);
                applyTitle();
                render();
            });
        ");
    }
}
