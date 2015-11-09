{include file="crud/_page_title.tpl" title="Листинг объектов <strong>{$_entity->getEntityName()}</strong>"}
{if !$status}
<h2>Что-то пошло не так!</h2>
Попробуйте вернуться на <a href="javascript:history.back()">предыдущую страницу</a>
    <pre>{$data|var_export}</pre>
{else}
<div class="row">
    <div class="col-lg-3">
        {include file="crud/list/pagination.tpl"}
    </div>
    <div class="col-lg-5">
        {include file="crud/list/actions.tpl"}
    </div>
    <div class="col-lg-4">
        {if $_entity->getMapper()->getIsSearchable()}
            {include file="crud/list/search.tpl"}
        {/if}
    </div>
</div>
<div class="row">
    <div class="col-lg-4">
        {include file="crud/list/multi.tpl"}
    </div>
</div>
<form method="post" id="list-form">
<table class="table table-striped table-bordered table-hover">
    <thead>
    <tr>
        <th class="col-lg-1">
        </th>
        <th class="col-lg-2">
            <button type="button" class="btn btn-default btn-xs" id="expand-header">
                <span class="glyphicon glyphicon-plus" aria-hidden="true"></span>
            </button>
            Действия
        </th>
        {assign var="map" value=$_entity->getMapper()->getMap()}
        {assign var="colWidth" value=ceil(10 / count($map))}
        {foreach from=$map key="fieldName" item="field"}
            {if isset($data.sort[$fieldName]) && $data.sort[$fieldName] > 0}
                {assign var="sort" value=-1}
            {else}
                {assign var="sort" value=1}
            {/if}
            <th class="col-lg-{$colWidth} {if isset($data.sort[$fieldName])}bg-info{/if}" {if !empty($field.description)} title="{$field.description|htmlspecialchars}"{/if}>{$field.name|htmlspecialchars}
                {if isset($field.role.sortable) && $field.role.sortable}<a
                    href="{$_application->getUrl($_route->getName(), ['q' => $data.query, 'sort' => [$fieldName => $sort], 'limit' => $data.items->getCurrentLimit(), 'offset' => $data.items->getCurrentSkip() ])}">
                    <span class="{if $sort == -1}fa fa-arrow-down{else}fa fa-arrow-up{/if}"></span></a>{/if}
            </th>
        {/foreach}
    </tr>
    </thead>
    <tbody>
        {foreach from=$data.items item="item" name="itemsForeach"}
        <tr>
            <td>
                <input type="checkbox" value="{$item->getIdValue()}" id="list-checkbox-{$smarty.foreach.itemsForeach.iteration}" name="items[]" class="chkbox">
            </td>
            <td>
                <ul>
                    {assign var="structure" value=$_entity->getModule()->getModuleRoutes()->getStructure()}
                    {foreach from=$structure[$_entity->getEntityUniqueName()]['actions'] key="routeName" item="routeAction"}
                        {if $routeAction->getType() != 2}{continue}{/if}
                        <li>
                            <a href="{$_slim->urlFor($routeName, [$_entity->getMapper()->getKey() => $item->getIdValue()])}">
                                {$routeAction->getName()|htmlspecialchars}
                            </a>
                        </li>
                    {/foreach}
                </ul>
            </td>
            {foreach from=$map key="fieldName" item="field"}
                <th>
                    {if $field.formType == "text"}
                        {$item->getFieldValue($fieldName)|htmlspecialchars}
                    {elseif ($field.formType == "datetimepicker" || $field.formType == "timepicker")}
                        {date('Y-m-d H:i:s', $item->getFieldValue($fieldName))}
                    {else}
                        {$item->getFieldValue($fieldName)|json_encode:384|htmlspecialchars}
                    {/if}
                </th>
            {/foreach}
        </tr>
    {/foreach}
    </tbody>
</table>
</form>
<div class="row">
    <div class="col-lg-4">
        {include file="crud/list/multi.tpl"}
    </div>
</div>
<div class="row">
    <div class="col-lg-4">
        {include file="crud/list/pagination.tpl" newPagination=true}
    </div>
</div>
<style>
    .table > thead > tr > th {
        vertical-align: top;
    }

    th span.grey {
        font-size: small;
        color: grey;
    }
</style>
<script type="application/javascript">
    var lastChecked = null;

    $(document).ready(function () {
        var $chkboxes = $('.chkbox');
        $chkboxes.click(function (e) {
            if (!lastChecked) {
                lastChecked = this;
                return;
            }

            if (e.shiftKey) {
                var start = $chkboxes.index(this);
                var end = $chkboxes.index(lastChecked);

                $chkboxes.slice(Math.min(start, end), Math.max(start, end) + 1).prop('checked', lastChecked.checked);
            }

            lastChecked = this;
        });

        $('.multi').click(function (event) {
            var listForm = $('#list-form');
            listForm.attr('action', event.target.pathname);
            listForm.submit();

            return false;
        });

        $('#expand-header').on('click', function (item) {
            var classList = $('#expand-header > span').attr('class').split(/\s+/);
            $.each(classList, function (index, item) {
                if (item == 'glyphicon-plus') {
                    $('#expand-header > span').toggleClass('glyphicon-plus').toggleClass('glyphicon-minus');
                    $('tr th').each(function (index, item) {
                        if ($(item).find('span.grey').length) {
                            $(item).children('span.grey').show();
                        } else if ($(item).attr('title') != undefined) {
                            $(item).append('<span class="grey"><br>' + $(item).attr('title') + '</span>');
                        }
                    });
                } else if (item == 'glyphicon-minus') {
                    $('#expand-header > span').toggleClass('glyphicon-plus').toggleClass('glyphicon-minus');
                    $('tr th span.grey').each(function (i, item) {
                        $(item).hide();
                    });
                }

            });
        });
    });
</script>
{/if}