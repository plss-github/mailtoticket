<?php

namespace GlpiPlugin\Mailtoticket;

use CommonITILObject;
use ITILFollowup;
use Laminas\Mail\Storage\Message;
use RequestType;
use Session;
use RuntimeException;
use Throwable;
use Ticket;

/**
 * Abre um chamado a partir de um e-mail usando o mesmo MailCollector::buildTicket()
 * do coletor nativo (requerente, anexos, conteúdo e regras de e-mail iguais).
 */
final class TicketCreator
{
    /**
     * Chamado ao qual o e-mail provavelmente se refere, por ordem de confiança:
     *  code    - código do GLPI (cabeçalhos In-Reply-To/References ou número no assunto, vários formatos)
     *  thread  - resposta (References/In-Reply-To) a um e-mail já importado por esta caixa
     *  subject - mesmo assunto de um e-mail já importado por esta caixa (ex.: protocolo do cliente)
     *  body    - "[GLPI #N]" no corpo (notificação citada)
     * Só considera chamados que o usuário pode ver.
     *
     * @return array{ticket:Ticket,via:string}|null
     */
    public static function relatedTicket(Mailbox $mailbox, Client $client, Message $message): ?array
    {
        $mb_id = (int) $mailbox->getID();

        // 1) core (cabeçalhos do GLPI e "[... #N]" no assunto)
        try {
            $item = $client->collectorShell()->getItemFromHeaders($message);
            if ($item instanceof Ticket && self::visible($item)) {
                return ['ticket' => $item, 'via' => 'code'];
            }
        } catch (Throwable) {
        }

        // 1b) número no assunto em outros formatos: [#52], #0000052, "Chamado 52", "[GLPI 0000052]"
        $subject = Client::subject($message);
        foreach (self::codesFromSubject($subject) as $id) {
            $t = new Ticket();
            if ($t->getFromDB($id) && self::visible($t)) {
                return ['ticket' => $t, 'via' => 'code'];
            }
        }

        // 2) conversa: responde a um e-mail que já virou chamado/acompanhamento
        if (($id = MailTicket::ticketByMessageKeys($mb_id, Client::referencedKeys($message))) !== null) {
            $t = new Ticket();
            if ($t->getFromDB($id) && self::visible($t)) {
                return ['ticket' => $t, 'via' => 'thread'];
            }
        }

        // 3) mesmo assunto de um e-mail já importado
        if (($sk = Client::subjectKey($subject)) !== null && ($id = MailTicket::ticketBySubjectKey($mb_id, $sk)) !== null) {
            $t = new Ticket();
            if ($t->getFromDB($id) && self::visible($t)) {
                return ['ticket' => $t, 'via' => 'subject'];
            }
        }

        // 4) "[GLPI #N]" no corpo (notificação citada na resposta)
        try {
            $body = mb_substr($client->collectorShell()->getBody($message), 0, 20000);
            if (preg_match('/\[\s*GLPI\s*#\s*0*(\d+)\s*\]/iu', $body, $m)) {
                $t = new Ticket();
                if ($t->getFromDB((int) $m[1]) && self::visible($t)) {
                    return ['ticket' => $t, 'via' => 'body'];
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function visible(Ticket $t): bool
    {
        return Session::haveAccessToEntity((int) $t->fields['entities_id'], true) && $t->canViewItem();
    }

    /** @return int[] números de chamado citados no assunto, na ordem em que aparecem */
    public static function codesFromSubject(string $subject): array
    {
        $found = [];
        foreach (
            [
                '/\[[^\]]*?#\s*0*(\d+)\s*\]/u',                              // [#52] [GLPI #0000052]
                '/\[\s*(?:glpi|chamado|ticket)\s+0*(\d+)\s*\]/iu',              // [GLPI 0000052]
                '/#\s*0*(\d+)/u',                                                // #0000052
                '/\b(?:chamado|ticket|incidente|requisi[cç][aã]o)\s*(?:n[ºo°.]*\s*)?#?\s*0*(\d+)/iu', // Chamado 52
            ] as $re
        ) {
            if (preg_match_all($re, $subject, $m)) {
                foreach ($m[1] as $n) {
                    if ((int) $n > 0) {
                        $found[(int) $n] = (int) $n;
                    }
                }
            }
        }
        return array_values($found);
    }

    /**
     * Adiciona o e-mail como acompanhamento do chamado a que ele responde.
     * Mesma lógica do coletor (conteúdo sem cabeçalho/rodapé de notificação, anexos, reabertura).
     */
    /** @param array{add_observer?:bool} $options */
    public static function addFollowup(Mailbox $mailbox, Client $client, string $uid, Message $message, array $options = []): int
    {
        global $CFG_GLPI;

        $key   = Client::messageKey($message);
        $state = MailTicket::getState((int) $mailbox->getID(), $key);
        if ($state !== null) {
            throw new RuntimeException(__('Este e-mail já foi tratado (chamado aberto ou descartado).', 'mailtoticket'));
        }

        $related = self::relatedTicket($mailbox, $client, $message);
        $ticket  = $related['ticket'] ?? null;
        if ($ticket === null) {
            throw new RuntimeException(__('Este e-mail não responde a nenhum chamado que você possa ver.', 'mailtoticket'));
        }
        if ((int) $ticket->fields['status'] === CommonITILObject::CLOSED) {
            throw new RuntimeException(__('O chamado está fechado. Abra um novo chamado ligado a ele.', 'mailtoticket'));
        }
        if (!$ticket->canAddFollowups()) {
            throw new RuntimeException(__('Você não tem permissão para adicionar acompanhamentos neste chamado.', 'mailtoticket'));
        }

        // A decisão é do atendente: sem isto o parser recusaria remetentes sem usuário
        // (use_anonymous_followups desligado) e trataria o e-mail como chamado novo.
        $old = $CFG_GLPI['use_anonymous_followups'] ?? 0;
        $CFG_GLPI['use_anonymous_followups'] = 1;
        try {
            $tkt = $client->collectorShell()->buildTicket($uid, $message, ['mailgates_id' => 0, 'play_rules' => false]);
        } finally {
            $CFG_GLPI['use_anonymous_followups'] = $old;
        }

        if (!empty($tkt['_blacklisted'])) {
            throw new RuntimeException(__('Remetente na lista de bloqueio (blacklist) do GLPI ou notificação do próprio GLPI.', 'mailtoticket'));
        }

        $content = (string) $tkt['content'];
        if (empty($tkt['_users_id_requester']) && !empty($tkt['_users_id_requester_notif']['alternative_email'][0])) {
            // Sem usuário: identifica o remetente no texto do acompanhamento
            $from    = $tkt['_users_id_requester_notif']['alternative_email'][0];
            $is_html = (bool) preg_match('/<\s*(html|body|div|p|br|table|span|a|img)\b/i', $content);
            $content = ($is_html ? '<p><b>' . htmlescape(__('De:', 'mailtoticket')) . '</b> ' . htmlescape($from) . '</p>' : __('De:', 'mailtoticket') . ' ' . $from . "\r\n\r\n") . $content;
        }

        $fup_input = $tkt;
        unset($fup_input['tickets_id'], $fup_input['_linkedto'], $fup_input['name']);
        $fup_input['itemtype']        = Ticket::class;
        $fup_input['items_id']        = (int) $ticket->getID();
        $fup_input['content']         = $content;
        $fup_input['add_reopen']      = 1;
        $fup_input['requesttypes_id'] = RequestType::getDefault('mailfollowup');

        if (!empty($tkt['users_id'])) {
            $_SESSION['mailcollector_user'] = $tkt['users_id'];
        }
        try {
            $id = (int) (new ITILFollowup())->add($fup_input);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        } finally {
            unset($_SESSION['mailcollector_user']);
        }
        if ($id <= 0) {
            throw new RuntimeException(__('O GLPI não conseguiu adicionar o acompanhamento.', 'mailtoticket'));
        }

        (new MailTicket())->add([
            'plugin_mailtoticket_mailboxes_id' => (int) $mailbox->getID(),
            'message_key'                      => $key,
            'status'                           => MailTicket::STATUS_FOLLOWUP,
            'subject_key'                      => Client::subjectKey(Client::subject($message)),
            'tickets_id'                       => (int) $ticket->getID(),
            'users_id'                         => (int) Session::getLoginUserID(),
        ]);

        // Opcional: remetente (se ainda não participa) vira observador do chamado
        if (!empty($options['add_observer'])) {
            $user_id = (int) ($tkt['_users_id_requester'] ?? 0);
            $email   = $tkt['_users_id_requester_notif']['alternative_email'][0] ?? null;
            $added   = self::addObserver($ticket, $user_id, $email);
            \Session::addMessageAfterRedirect(htmlescape(
                $added
                    ? __('O remetente foi adicionado como observador do chamado.', 'mailtoticket')
                    : __('O remetente já participa do chamado (ou você não pode alterar os atores): não foi adicionado como observador.', 'mailtoticket')
            ), false, $added ? INFO : WARNING);
        }

        return (int) $ticket->getID();
    }

    /**
     * Ator (requerente, observador ou atribuído) do chamado que corresponde ao remetente, se houver.
     *
     * @return string|null 'requester' | 'observer' | 'assigned'
     */
    public static function senderRole(Ticket $ticket, int $user_id, ?string $email): ?string
    {
        global $DB;

        $or = [];
        if ($user_id > 0) {
            $or[] = ['users_id' => $user_id];
        }
        if ($email !== null && $email !== '') {
            $or[] = ['alternative_email' => $email];
        }
        if ($or === []) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => (int) $ticket->getID(), 'OR' => $or],
            'LIMIT' => 1,
        ])->current();
        if (!$row) {
            return null;
        }
        return match ((int) $row['type']) {
            \CommonITILActor::REQUESTER => 'requester',
            \CommonITILActor::OBSERVER  => 'observer',
            default                     => 'assigned',
        };
    }

    private static function addObserver(Ticket $ticket, int $user_id, ?string $email): bool
    {
        if (!$ticket->canUpdateItem() || self::senderRole($ticket, $user_id, $email) !== null) {
            return false;
        }
        $input = [
            'tickets_id'       => (int) $ticket->getID(),
            'type'             => \CommonITILActor::OBSERVER,
            'use_notification' => 1,
        ];
        if ($user_id > 0) {
            $input['users_id'] = $user_id;
        } elseif ($email) {
            $input['users_id']          = 0;
            $input['alternative_email'] = $email;
        } else {
            return false;
        }
        return (bool) (new \Ticket_User())->add($input);
    }

    /**
     * @param array{title?:string,itilcategories_id?:int,use_requester?:bool} $options
     */
    public static function create(Mailbox $mailbox, Client $client, string $uid, Message $message, array $options = []): int
    {
        $key = Client::messageKey($message);
        $state = MailTicket::getState((int) $mailbox->getID(), $key);
        if ($state !== null && $state['status'] === MailTicket::STATUS_DISCARDED) {
            throw new RuntimeException(__('Este e-mail foi descartado. Restaure-o antes de abrir um chamado.', 'mailtoticket'));
        }
        if ($state !== null) {
            throw new RuntimeException(sprintf(__('Este e-mail já gerou o chamado #%d.', 'mailtoticket'), $state['tickets_id']));
        }

        $mc  = $client->collectorShell();
        $tkt = $mc->buildTicket($uid, $message, [
            'mailgates_id' => 0,
            'play_rules'   => true,
        ]);

        if (!empty($tkt['_blacklisted'])) {
            throw new RuntimeException(__('Remetente na lista de bloqueio (blacklist) do GLPI ou notificação do próprio GLPI.', 'mailtoticket'));
        }
        if (isset($tkt['_refuse_email_with_response']) || isset($tkt['_refuse_email_no_response'])) {
            throw new RuntimeException(__('Uma regra de e-mail (coletor) recusou este e-mail.', 'mailtoticket'));
        }

        // Resposta a um chamado existente: aqui sempre abrimos um chamado novo, ligado ao anterior.
        if (isset($tkt['tickets_id'])) {
            $tkt['_linkedto'] = $tkt['tickets_id'];
            unset($tkt['tickets_id']);
        }
        unset($tkt['add_reopen']);

        // Caixas são por entidade: o chamado nasce na entidade da caixa
        // (a regra padrão do coletor atribui a raiz a tudo, o que ignoraria a entidade da caixa).
        $tkt['entities_id'] = (int) $mailbox->fields['entities_id'];

        // Padrões da caixa (que por sua vez caem nos da entidade); regras de e-mail têm prioridade
        $cfg = $mailbox->getTicketDefaults();
        if ($cfg['tickettype'] > 0 && !isset($tkt['type'])) {
            $tkt['type'] = $cfg['tickettype'];
        }
        if ($cfg['urgency'] > 0 && (int) ($tkt['urgency'] ?? 3) === 3) {
            $tkt['urgency'] = $cfg['urgency']; // 3 = padrão do coletor; outro valor veio de regra
        }
        if ($cfg['itilcategories_id'] > 0 && empty($tkt['itilcategories_id'])) {
            $tkt['itilcategories_id'] = $cfg['itilcategories_id'];
        }

        // Escolhas feitas na tela de abertura
        $category = (int) ($options['itilcategories_id'] ?? 0);
        if ($category > 0) {
            $tkt['itilcategories_id'] = $category;
        }
        $title = trim((string) ($options['title'] ?? ''));
        if ($title !== '') {
            $tkt['name'] = $title;
        }
        if (!($options['use_requester'] ?? true)) {
            // Remetente NÃO vira requerente
            unset(
                $tkt['_users_id_requester'],
                $tkt['_users_id_requester_notif']
            );
            $tkt['users_id'] = (int) \Session::getLoginUserID();
        }

        // Preferências de notificação do autor (igual ao coletor)
        if (!empty($tkt['users_id'])) {
            $_SESSION['mailcollector_user'] = $tkt['users_id'];
        } elseif (isset($tkt['_users_id_requester_notif']['alternative_email'][0])) {
            $_SESSION['mailcollector_user'] = $tkt['_users_id_requester_notif']['alternative_email'][0];
        }

        try {
            $ticket = new Ticket();
            $id     = (int) $ticket->add($tkt);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        } finally {
            unset($_SESSION['mailcollector_user']);
        }

        if ($id <= 0) {
            throw new RuntimeException(__('O GLPI não conseguiu criar o chamado (verifique os campos obrigatórios da entidade).', 'mailtoticket'));
        }

        (new MailTicket())->add([
            'plugin_mailtoticket_mailboxes_id' => (int) $mailbox->getID(),
            'message_key'                      => $key,
            'subject_key'                      => Client::subjectKey(Client::subject($message)),
            'tickets_id'                       => $id,
            'users_id'                         => (int) \Session::getLoginUserID(),
        ]);

        return $id;
    }
}
