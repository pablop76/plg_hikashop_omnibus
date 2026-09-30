<?php
namespace Pablop76\Plugin\Hikashop\Omnibus\Field;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * Pole w ustawieniach wtyczki: przyciski czyszczenia historii cen (przez com_ajax).
 */
class HistoryField extends FormField
{
    protected $type = 'History';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        $token = Session::getFormToken();
        $base = 'index.php?option=com_ajax&plugin=omnibus&group=hikashop&format=json&' . $token . '=1';
        $id = 'omnibus-clean';

        $msgProduct = Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_CONFIRM_PRODUCT');
        $msgOld = Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_CONFIRM_OLD');
        $msgError = Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_ERROR');

        $js = 'document.addEventListener("DOMContentLoaded",function(){'
            . 'var box=document.getElementById(' . json_encode($id) . ');if(!box){return;}'
            . 'var out=box.querySelector(".omnibus-clean-result");'
            . 'function run(url,ask){if(!window.confirm(ask)){return;}'
            . 'out.textContent="...";'
            . 'fetch(url,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){'
            . 'var d=j&&j.data;if(Array.isArray(d)){d=d[0];}'
            . 'out.textContent=(d&&d.message)?d.message:' . json_encode($msgError) . ';'
            . '}).catch(function(){out.textContent=' . json_encode($msgError) . ';});}'
            . 'box.querySelector(".omnibus-clean-product").addEventListener("click",function(){'
            . 'var v=parseInt(box.querySelector(".omnibus-clean-id").value,10);'
            . 'run(' . json_encode($base) . '+"&task=product&product_id="+(isNaN(v)?0:v),' . json_encode($msgProduct) . ');});'
            . 'box.querySelector(".omnibus-clean-old").addEventListener("click",function(){'
            . 'run(' . json_encode($base) . '+"&task=old",' . json_encode($msgOld) . ');});'
            . '});';

        Factory::getApplication()->getDocument()->addScriptDeclaration($js);

        return '<div id="' . $id . '">'
            . '<p class="form-text">' . Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_INFO') . '</p>'
            . '<div class="input-group mb-2" style="max-width:32rem">'
            . '<input type="number" min="1" class="form-control omnibus-clean-id" placeholder="'
            . htmlspecialchars(Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_ID_PLACEHOLDER'), ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="button" class="btn btn-danger omnibus-clean-product">'
            . Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_PRODUCT_BTN') . '</button>'
            . '</div>'
            . '<button type="button" class="btn btn-secondary omnibus-clean-old">'
            . Text::_('PLG_HIKASHOP_OMNIBUS_CLEAN_OLD_BTN') . '</button>'
            . '<p class="omnibus-clean-result mt-2 fw-bold" role="status"></p>'
            . '</div>';
    }
}
