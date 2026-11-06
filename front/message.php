<?php

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Mailtoticket\Client;
use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\MessageView;
use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\Rights;
use GlpiPlugin\Mailtoticket\TicketCreator;

Session::checkRight(Rights::NAME, READ);

$mailbox_id = (int) ($_REQUEST['mailbox'] ?? 0);
$uid        = (string) ($_REQUEST['uid'] ?? '');

$mailbox = new Mailbox();
if ($mailbox_id <= 0 || $uid === '' || !$mailbox->getFromDB($mailbox_id) || !$mailbox->fields['is_active'] || !Rights::canUseMailbox($mailbox)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$back   = PLUGIN_MAILTOTICKET_WEBDIR . '/front/messages.php?mailbox=' . $mailbox_id;
$self   = PLUGIN_MAILTOTICKET_WEBDIR . '/front/message.php?mailbox=' . $mailbox_id . '&uid=' . rawurlencode($uid);
$client = new Client($mailbox);

try {
    $message = $client->get($uid);
} catch (Throwable $e) {
    Session::addMessageAfterRedirect(htmlescape(__('Não foi possível abrir o e-mail: ', 'mailtoticket') . $e->getMessage()), false, ERROR);
    Html::redirect($back);
}

if (isset($_POST['create_ticket']) || isset($_POST['add_followup'])) {
    if (!Rights::canOpenTicket()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    $tickets_id = null;
    $error      = null;
    $followup   = isset($_POST['add_followup']);
    try {
        if ($followup) {
            $tickets_id = TicketCreator::addFollowup($mailbox, $client, $uid, $message, ['add_observer' => !empty($_POST['add_observer'])]);
        } else {
            $category = (int) ($_POST['itilcategories_id'] ?? 0);
            if ($category <= 0) {
                throw new RuntimeException(__('Selecione a categoria do chamado.', 'mailtoticket'));
            }
            $tickets_id = TicketCreator::create($mailbox, $client, $uid, $message, [
                'itilcategories_id' => $category,
                'title'             => (string) ($_POST['title'] ?? ''),
                'use_requester'     => !empty($_POST['use_requester']),
            ]);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $client->restoreUnseen();

    // Html::redirect() lança exceção no GLPI 11: fica fora do try/catch
    if ($tickets_id) {
        Session::addMessageAfterRedirect(htmlescape(
            $followup
                ? sprintf(__('E-mail adicionado como acompanhamento do chamado #%d.', 'mailtoticket'), $tickets_id)
                : sprintf(__('Chamado #%d criado a partir do e-mail.', 'mailtoticket'), $tickets_id)
        ));
        Html::redirect(Ticket::getFormURLWithID($tickets_id));
    }
    Session::addMessageAfterRedirect(htmlescape((string) $error), false, ERROR);
    Html::redirect($self);
}

$context = MessageView::context($mailbox, $client, $uid, $message);

Html::header(__('E-mail', 'mailtoticket'), $self, 'helpdesk', Menu::class, 'messages');
Menu::renderSubNav('messages');
TemplateRenderer::getInstance()->display('@mailtoticket/message.html.twig', $context);
Html::footer();
