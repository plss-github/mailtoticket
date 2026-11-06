<?php

use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\Rights;

Session::checkRight(Rights::NAME, UPDATE);

$mailbox = new Mailbox();

if (isset($_POST['add'])) {
    $mailbox->check(-1, CREATE, $_POST);
    $newID = $mailbox->add($_POST);
    Html::redirect($newID ? $mailbox->getFormURLWithID($newID) : Mailbox::getFormURL());
} elseif (isset($_POST['update'])) {
    $mailbox->check($_POST['id'], UPDATE);
    $mailbox->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $mailbox->check($_POST['id'], PURGE);
    $mailbox->delete($_POST, true);
    Html::redirect(PLUGIN_MAILTOTICKET_WEBDIR . '/front/mailbox.php');
} else {
    Html::header(Mailbox::getTypeName(1), PLUGIN_MAILTOTICKET_WEBDIR . '/front/mailbox.form.php', 'helpdesk', Menu::class, 'mailbox');
    Menu::renderSubNav('mailbox');
    $mailbox->display(['id' => (int) ($_GET['id'] ?? -1)]);
    Html::footer();
}
