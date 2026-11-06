<?php

use GlpiPlugin\Mailtoticket\Mailbox;
use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\Rights;

Session::checkRight(Rights::NAME, UPDATE);

Html::header(Mailbox::getTypeName(2), PLUGIN_MAILTOTICKET_WEBDIR . '/front/mailbox.php', 'helpdesk', Menu::class, 'mailbox');
Menu::renderSubNav('mailbox');

echo '<div class="mb-3"><a class="btn btn-primary" href="' . htmlescape(Mailbox::getFormURL()) . '">'
    . '<i class="ti ti-plus me-1"></i>' . htmlescape(__('Nova caixa de e-mail', 'mailtoticket')) . '</a></div>';

Search::show(Mailbox::class);

Html::footer();
