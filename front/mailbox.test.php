<?php

use GlpiPlugin\Mailtoticket\Client;
use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\Rights;

Session::checkRight(Rights::NAME, UPDATE);

$mailbox = new Mailbox();
$id      = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !$mailbox->getFromDB($id) || !$mailbox->canViewItem()) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}

$result = Client::test($mailbox);
Session::addMessageAfterRedirect(htmlescape($result['message']), false, $result['ok'] ? INFO : ERROR);
Html::redirect(Mailbox::getFormURLWithID($id));
