<?php

use GlpiPlugin\Mailtoticket\MailTicket;
use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\Rights;

Session::checkRight(Rights::NAME, DELETE);

if (!isset($_POST['action'])) {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}

$mailbox = new Mailbox();
$id      = (int) ($_POST['mailbox'] ?? 0);
if ($id <= 0 || !$mailbox->getFromDB($id) || !Rights::canUseMailbox($mailbox)) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$keys = !empty($_POST['one']) ? [(string) $_POST['one']] : array_map('strval', (array) ($_POST['keys'] ?? []));

if ($_POST['action'] === 'restore') {
    $n = MailTicket::restoreMails($id, $keys);
    Session::addMessageAfterRedirect(htmlescape(sprintf(__('%d e-mail(s) restaurado(s).', 'mailtoticket'), $n)));
} else {
    $n = MailTicket::discardMails($id, $keys);
    Session::addMessageAfterRedirect(htmlescape(sprintf(__('%d e-mail(s) descartado(s).', 'mailtoticket'), $n)));
}

// Volta só para páginas do próprio plugin
$return = (string) ($_POST['return'] ?? '');
$prefix = PLUGIN_MAILTOTICKET_WEBDIR . '/front/';
Html::redirect(str_starts_with($return, $prefix) && !str_contains($return, "\n") ? $return : $prefix . 'messages.php?mailbox=' . $id);
