<?php

use GlpiPlugin\Mailtoticket\Menu;
use GlpiPlugin\Mailtoticket\ProfileTab;
use GlpiPlugin\Mailtoticket\Rights;

define('PLUGIN_MAILTOTICKET_VERSION', '1.4.0');
define('PLUGIN_MAILTOTICKET_MIN_GLPI', '11.0.0'); // inclusivo
define('PLUGIN_MAILTOTICKET_MAX_GLPI', '11.1.0'); // exclusivo: aceita toda a série 11.0.x

global $CFG_GLPI;
define('PLUGIN_MAILTOTICKET_WEBDIR', ($CFG_GLPI['root_doc'] ?? '') . '/plugins/mailtoticket');

function plugin_init_mailtoticket()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['config_page']['mailtoticket'] = 'front/config.form.php';

    // O menu é montado uma vez por sessão: invalida o cache quando a versão do plugin muda.
    if (Session::getLoginUserID() && ($_SESSION['plugin_mailtoticket_menu'] ?? null) !== PLUGIN_MAILTOTICKET_VERSION) {
        unset($_SESSION['glpimenu']);
        $_SESSION['plugin_mailtoticket_menu'] = PLUGIN_MAILTOTICKET_VERSION;
    }

    Plugin::registerClass(ProfileTab::class, ['addtabon' => ['Profile']]);

    // Sem nenhum direito do plugin, a funcionalidade nem aparece no menu
    if (Session::getLoginUserID() && Session::haveRightsOr(Rights::NAME, [READ, UPDATE])) {
        $PLUGIN_HOOKS['menu_toadd']['mailtoticket'] = [
            'helpdesk' => Menu::class,
        ];
    }
}

function plugin_version_mailtoticket()
{
    return [
        'name'         => __('E-mail para chamado', 'mailtoticket'),
        'version'      => PLUGIN_MAILTOTICKET_VERSION,
        'author'       => 'Matheus Schmidt',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILTOTICKET_MIN_GLPI,
                'max' => PLUGIN_MAILTOTICKET_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}
