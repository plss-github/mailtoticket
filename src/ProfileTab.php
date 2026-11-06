<?php

namespace GlpiPlugin\Mailtoticket;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "E-mail para chamado" em Administração > Perfis.
 */
class ProfileTab extends Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('E-mail para chamado', 'mailtoticket');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-mail-opened');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            self::showForProfile((int) $item->getID());
        }
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Mailbox::class,
                'label'    => __('E-mail para chamado', 'mailtoticket'),
                'field'    => Rights::NAME,
                'rights'   => [
                    READ   => __('Ver caixas e e-mails', 'mailtoticket'),
                    CREATE => __('Abrir chamado a partir do e-mail', 'mailtoticket'),
                    DELETE => __('Descartar / restaurar e-mails', 'mailtoticket'),
                    UPDATE => __('Gerenciar caixas e configuração', 'mailtoticket'),
                ],
            ],
        ];
    }

    public static function showForProfile(int $profiles_id): void
    {
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }
        $can_edit = Session::haveRight('profile', UPDATE);

        echo "<div class='mt-3'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        }
        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $can_edit,
            'default_class' => 'tab_bg_2',
            'title'         => __('E-mail para chamado', 'mailtoticket'),
        ]);
        if ($can_edit) {
            echo "<div class='text-center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }
}
