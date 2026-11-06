<?php

use GlpiPlugin\Mailtoticket\Configuration;
use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\Rights;

Session::checkRight(Rights::NAME, UPDATE);

if (isset($_POST['update'])) {
    $eid = (int) ($_POST['entities_id'] ?? 0);
    if (!Session::haveAccessToEntity($eid)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Configuration::save($eid, $_POST);
    Session::addMessageAfterRedirect(htmlescape(__('Configuração salva.', 'mailtoticket')));
    Html::redirect(PLUGIN_MAILTOTICKET_WEBDIR . '/front/config.form.php');
}

Html::header(__('Configuração', 'mailtoticket'), PLUGIN_MAILTOTICKET_WEBDIR . '/front/config.form.php', 'helpdesk', Menu::class, 'config');
Menu::renderSubNav('config');
Configuration::renderForm((int) $_SESSION['glpiactive_entity']);
Html::footer();
