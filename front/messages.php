<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Mailtoticket\Client;
use GlpiPlugin\Mailtoticket\Configuration;
use GlpiPlugin\Mailtoticket\MailTicket;
use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\Rights;
use GlpiPlugin\Mailtoticket\TicketCreator;

global $DB;

Session::checkRight(Rights::NAME, READ);

// Só as caixas das entidades às quais o usuário tem acesso
$mailboxes = [];
foreach (
    $DB->request([
        'FROM'  => Mailbox::getTable(),
        'WHERE' => ['is_active' => 1] + getEntitiesRestrictCriteria(Mailbox::getTable(), '', '', true),
        'ORDER' => 'name',
    ]) as $row
) {
    $mailboxes[(int) $row['id']] = ($row['last_error'] ? '⚠ ' : '') . $row['name']; // ⚠ = a última conexão falhou
}

// Caixa aberta: a pedida; senão a última usada nesta sessão; senão a primeira da lista (ordem alfabética)
$last    = (int) ($_SESSION['plugin_mailtoticket_last_mailbox'] ?? 0);
$current = isset($_GET['mailbox']) ? (int) $_GET['mailbox'] : (isset($mailboxes[$last]) ? $last : (int) (array_key_first($mailboxes) ?? 0));
if (isset($mailboxes[$current])) {
    $_SESSION['plugin_mailtoticket_last_mailbox'] = $current;
}
$start   = max(0, (int) ($_GET['start'] ?? 0));

// Situação: a pedida; senão a última usada nesta sessão; senão "pendentes"
$valid_status = ['pending', 'linked', 'discarded', 'all'];
$status       = in_array($_GET['status'] ?? ($_SESSION['plugin_mailtoticket_last_status'] ?? 'pending'), $valid_status, true)
    ? ($_GET['status'] ?? ($_SESSION['plugin_mailtoticket_last_status'] ?? 'pending'))
    : 'pending';
$_SESSION['plugin_mailtoticket_last_status'] = $status;
$valid_date = static fn ($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : '';
$filters = [
    'q'      => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    'since'  => $valid_date($_GET['since'] ?? ''),
    'until'  => $valid_date($_GET['until'] ?? ''),
    'unseen' => !empty($_GET['unseen']),
];

$result = ['items' => [], 'total' => 0, 'next' => null, 'box_total' => 0];
$error  = null;

if ($current > 0 && isset($mailboxes[$current])) {
    $mailbox = new Mailbox();
    $mailbox->getFromDB($current);
    if (!Rights::canUseMailbox($mailbox)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $per_page = Configuration::getForEntity((int) $mailbox->fields['entities_id'])['per_page'];
    if (!empty($_GET['refresh'])) {
        Client::bustCache($current); // botão Atualizar: ignora a lista em cache
    }
    try {
        $result = (new Client($mailbox))->scan($filters, $status, $start, $per_page);
        if ($mailbox->fields['last_error'] !== null || strtotime((string) $mailbox->fields['last_check']) < time() - 600) {
            Mailbox::recordCheck($current, null); // conectou: limpa o erro (e no máximo 1 gravação a cada 10 min)
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($error !== $mailbox->fields['last_error']) {
            Mailbox::recordCheck($current, $error);
        }
    }

    // Dicas por e-mail pendente: assunto cita chamado existente ("Re: [GLPI #58]", "Chamado 63"...)
    $hints = [];
    foreach ($result['items'] as $i => $it) {
        if ($it['tickets_id'] === null && !$it['discarded']) {
            $hints[$i] = TicketCreator::codesFromSubject((string) $it['subject']);
        }
    }
    $wanted = array_unique(array_merge([], ...array_values($hints)));
    $known  = [];
    if ($wanted !== []) {
        foreach (
            $DB->request([
                'SELECT' => ['id', 'status'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $wanted, 'is_deleted' => 0] + getEntitiesRestrictCriteria('glpi_tickets', '', '', true),
            ]) as $t
        ) {
            $known[(int) $t['id']] = (int) $t['status'] === Ticket::CLOSED;
        }
    }
    foreach ($hints as $i => $codes) {
        foreach ($codes as $code) {
            if (isset($known[$code])) {
                $result['items'][$i]['hint'] = ['id' => $code, 'closed' => $known[$code]];
                break;
            }
        }
    }
}

$base   = PLUGIN_MAILTOTICKET_WEBDIR . '/front';
$params = ['mailbox' => $current, 'status' => $status, 'q' => $filters['q'], 'since' => $filters['since'],
           'until' => $filters['until'], 'unseen' => $filters['unseen'] ? 1 : ''];
$url    = static fn (array $extra) => $base . '/messages.php?' . http_build_query(array_filter($extra + $params, static fn ($v) => $v !== '' && $v !== null));

// Seletor de caixa com busca (select2): só o nome da caixa, em ordem alfabética
$rand = mt_rand();
$select_html = Dropdown::showFromArray('mailbox', $mailboxes, [
    'value'   => $current,
    'display' => false,
    'width'   => '100%',
    'rand'    => $rand,
]) . Html::scriptBlock("$(function () { $('#dropdown_mailbox{$rand}').on('change', function () { this.form.requestSubmit(); }); });");

Html::header(__('Caixas de e-mail', 'mailtoticket'), $base . '/messages.php', 'helpdesk', Menu::class, 'messages');
Menu::renderSubNav('messages');

TemplateRenderer::getInstance()->display('@mailtoticket/messages.html.twig', [
    'mailboxes'   => $mailboxes,
    'mailbox_select' => $select_html,
    'current'     => $current,
    'items'       => $result['items'],
    'total'       => $result['total'],
    'next_url'    => $result['next'] !== null ? $url(['start' => $result['next']]) : null,
    'refresh_url' => $url(['refresh' => 1, 'start' => '']),
    'current_error' => $error,
    'text_search' => $filters['q'] !== '',
    'all_url'     => $url(['status' => 'all', 'start' => '']),
    'first_url'   => $start > 0 ? $url(['start' => '']) : null,
    'return_url'  => $url(['start' => $start > 0 ? $start : '']),
    'status'      => $status,
    'filters'     => $filters,
    'filtered'    => $filters['q'] !== '' || $filters['since'] !== '' || $filters['until'] !== '' || $filters['unseen'],
    'error'       => $error,
    'base'        => $base,
    'can_manage'  => Rights::canManage(),
    'can_discard' => Rights::canDiscard(),
    'form_url'    => Mailbox::getFormURL(),
    'ticket_url'  => Ticket::getFormURL(),
]);

Html::footer();
