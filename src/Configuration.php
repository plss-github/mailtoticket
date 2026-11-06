<?php

namespace GlpiPlugin\Mailtoticket;

use Dropdown;
use Entity;
use Html;
use ITILCategory;
use Ticket;

/**
 * Configuração por entidade. Cada campo guarda -2 ("herdar da entidade pai");
 * a entidade raiz sempre tem valores próprios.
 */
final class Configuration
{
    public const INHERIT = -2;

    public const FIELDS = ['per_page', 'tickettype', 'urgency', 'itilcategories_id'];

    private const DEFAULTS = ['per_page' => 25, 'tickettype' => 0, 'urgency' => 0, 'itilcategories_id' => 0];

    public static function getTable(): string
    {
        return 'glpi_plugin_mailtoticket_configs';
    }

    /** Linha da própria entidade, sem resolver herança. */
    public static function getRaw(int $entities_id): array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['entities_id' => $entities_id], 'LIMIT' => 1])->current();
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = isset($row[$f]) ? (int) $row[$f] : ($entities_id === 0 ? self::DEFAULTS[$f] : self::INHERIT);
        }
        return $out;
    }

    /**
     * Valores efetivos para a entidade (sobe pela árvore até achar valor próprio).
     *
     * @return array{per_page:int,tickettype:int,urgency:int,itilcategories_id:int}
     */
    public static function getForEntity(int $entities_id): array
    {
        $chain = [$entities_id];
        if ($entities_id !== 0) {
            $chain = array_merge($chain, array_reverse(array_values(getAncestorsOf('glpi_entities', $entities_id))));
        }
        if (!in_array(0, $chain, true)) {
            $chain[] = 0;
        }

        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f] = self::DEFAULTS[$f];
            foreach ($chain as $eid) {
                $v = self::getRaw((int) $eid)[$f];
                if ($v !== self::INHERIT) {
                    $out[$f] = $v;
                    break;
                }
            }
        }
        $out['per_page'] = min(100, max(5, $out['per_page']));
        return $out;
    }

    public static function getCategoryName(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $cat = new ITILCategory();
        return $cat->getFromDB($id) ? (string) $cat->fields['completename'] : '';
    }

    public static function save(int $entities_id, array $input): void
    {
        global $DB;

        $row = [];
        foreach (self::FIELDS as $f) {
            if ($entities_id !== 0 && !empty($input['inherit'][$f])) {
                $row[$f] = self::INHERIT;
                continue;
            }
            $v = (int) ($input[$f] ?? 0);
            $row[$f] = match ($f) {
                'per_page'   => min(100, max(5, $v)),
                'tickettype' => in_array($v, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true) ? $v : 0,
                'urgency'    => ($v >= 1 && $v <= 5) ? $v : 0,
                default      => max(0, $v),
            };
        }

        $exists = countElementsInTable(self::getTable(), ['entities_id' => $entities_id]) > 0;
        if ($exists) {
            $DB->update(self::getTable(), $row, ['entities_id' => $entities_id]);
        } else {
            $DB->insert(self::getTable(), ['entities_id' => $entities_id] + $row);
        }
    }

    /** Formulário da entidade ativa. */
    public static function renderForm(int $entities_id): void
    {
        $raw     = self::getRaw($entities_id);
        $is_root = $entities_id === 0;
        $entity  = new Entity();
        $parent  = (!$is_root && $entity->getFromDB($entities_id)) ? (int) $entity->fields['entities_id'] : 0;
        $parent_values = self::getForEntity($parent); // valores que seriam herdados
        $action  = PLUGIN_MAILTOTICKET_WEBDIR . '/front/config.form.php';

        echo '<div class="alert alert-info d-flex align-items-center">';
        echo '<i class="ti ti-building me-2 fs-2"></i><div>'
            . sprintf(
                htmlescape(__('Configuração da entidade: %s. Para configurar outra entidade, troque a entidade ativa no seletor no topo da página.', 'mailtoticket')),
                '<strong>' . htmlescape(Dropdown::getDropdownName('glpi_entities', $entities_id)) . '</strong>'
            )
            . '</div></div>';

        echo "<form method='post' action='" . htmlescape($action) . "'>";
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>"
            . htmlescape(__('Configuração do E-mail para chamado', 'mailtoticket')) . '</h3></div>';
        echo "<div class='card-body'><table class='tab_cadre_fixe'>";
        echo '<tr><th>' . __('Opção', 'mailtoticket') . '</th><th>' . __('Valor', 'mailtoticket') . '</th>'
            . ($is_root ? '' : '<th>' . __('Herdar da entidade pai', 'mailtoticket') . '</th>') . '</tr>';

        $row = static function (string $field, string $label, callable $control) use ($raw, $is_root, $parent_values): void {
            $inherit = $raw[$field] === self::INHERIT;
            echo '<tr class="tab_bg_1"><td>' . $label . '</td><td><div data-mtt-field="' . $field . '">';
            $control($inherit ? $parent_values[$field] : $raw[$field], $inherit);
            echo '</div></td>';
            if (!$is_root) {
                echo '<td><input type="checkbox" class="form-check-input mtt-inherit" name="inherit[' . $field . ']" value="1" '
                    . ($inherit ? 'checked' : '') . ' data-target="' . $field . '"></td>';
            }
            echo '</tr>';
        };

        $row('per_page', htmlescape(__('E-mails por página', 'mailtoticket')), static function ($v) {
            echo Html::input('per_page', ['type' => 'number', 'min' => 5, 'max' => 100, 'value' => $v]);
        });

        echo '<tr class="tab_bg_1"><th colspan="' . ($is_root ? 2 : 3) . '">'
            . htmlescape(__('Valores padrão do chamado criado a partir do e-mail (as regras de e-mail podem sobrescrever)', 'mailtoticket'))
            . '</th></tr>';

        $row('tickettype', htmlescape(__('Tipo')), static function ($v) {
            Dropdown::showFromArray('tickettype', [
                0                     => Dropdown::EMPTY_VALUE,
                Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
                Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
            ], ['value' => $v]);
        });

        $row('urgency', htmlescape(__('Urgência')), static function ($v) {
            $urgencies = [0 => Dropdown::EMPTY_VALUE];
            for ($i = 1; $i <= 5; $i++) {
                $urgencies[$i] = Ticket::getUrgencyName($i);
            }
            Dropdown::showFromArray('urgency', $urgencies, ['value' => $v]);
        });

        $row('itilcategories_id', htmlescape(__('Categoria')), static function ($v) use ($entities_id) {
            ITILCategory::dropdown(['name' => 'itilcategories_id', 'value' => $v, 'entity' => $entities_id, 'entity_sons' => true]);
        });

        echo '</table></div>';
        echo "<div class='card-footer text-center'>"
            . Html::hidden('entities_id', ['value' => $entities_id])
            . Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary'])
            . '</div></div>';
        Html::closeForm();

        // Herdar = desabilita o controle
        echo Html::scriptBlock("
            $(function () {
                $('.mtt-inherit').on('change', function () {
                    const box = $('[data-mtt-field=\"' + $(this).data('target') + '\"]');
                    box.find('input, select').prop('disabled', this.checked);
                    box.css('opacity', this.checked ? .45 : 1);
                }).trigger('change');
            });
        ");
    }
}
