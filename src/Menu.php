<?php

namespace GlpiPlugin\Mailtoticket;

use CommonGLPI;

class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return __('E-mail para chamado', 'mailtoticket');
    }

    public static function getIcon()
    {
        return 'ti ti-mail-opened';
    }

    public static function getMenuContent()
    {
        return [
            'title'   => self::getMenuName(),
            // primeira tela a que o usuário tem acesso
            'page'    => PLUGIN_MAILTOTICKET_WEBDIR . '/front/' . (Rights::canView() ? 'messages.php' : 'mailbox.php'),
            'icon'    => self::getIcon(),
            'options' => [
                'messages' => [
                    'title' => __('Caixas de e-mail', 'mailtoticket'),
                    'page'  => PLUGIN_MAILTOTICKET_WEBDIR . '/front/messages.php',
                    'icon'  => 'ti ti-inbox',
                ],
                'mailbox'  => [
                    'title' => __('Caixas cadastradas', 'mailtoticket'),
                    'page'  => PLUGIN_MAILTOTICKET_WEBDIR . '/front/mailbox.php',
                    'icon'  => 'ti ti-settings',
                    'links' => ['add' => Mailbox::getFormURL(false)],
                ],
                'config'   => [
                    'title' => __('Configuração', 'mailtoticket'),
                    'page'  => PLUGIN_MAILTOTICKET_WEBDIR . '/front/config.form.php',
                    'icon'  => 'ti ti-adjustments',
                ],
            ],
        ];
    }

    /**
     * Barra de abas exibida no topo de todas as telas do plugin (o menu lateral
     * do GLPI 11 mostra só uma entrada por plugin).
     */
    public static function renderSubNav(string $active): void
    {
        $base = PLUGIN_MAILTOTICKET_WEBDIR . '/front';
        $tabs = [['messages', __('E-mails', 'mailtoticket'), $base . '/messages.php', 'ti ti-inbox', Rights::canView()]];
        if (Rights::canManage()) {
            $tabs[] = ['mailbox', __('Caixas cadastradas', 'mailtoticket'), $base . '/mailbox.php', 'ti ti-mail-cog', true];
            $tabs[] = ['config', __('Configuração', 'mailtoticket'), $base . '/config.form.php', 'ti ti-adjustments', true];
        }

        echo '<ul class="nav nav-tabs mb-3">';
        foreach ($tabs as [$key, $label, $url, $icon, $visible]) {
            if (!$visible) {
                continue;
            }
            echo '<li class="nav-item"><a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . htmlescape($url) . '">'
                . '<i class="' . $icon . ' me-1"></i>' . htmlescape($label) . '</a></li>';
        }
        echo '</ul>';
    }
}
