{include file="crud/_page_title.tpl" title="Листинг объектов <strong>{$_entity->getEntityName()}</strong>"}
{if !$status}
    <h2>Что-то пошло не так!</h2>
    Попробуйте вернуться на
    <a href="javascript:history.back()">предыдущую страницу</a>
    <pre>{$data|var_export}</pre>
{else}
    {block name=head}
        <link rel="stylesheet" href="/custom/sds/crud/list_tpl/style.css">
    {/block}
    <div class="row justify-content-between">
        <div class="col-lg-3">
            {include file="crud/list/pagination.tpl"}
        </div>
        <div class="col-lg-4">
            {if $_entity->getMapper()->getIsSearchable()}
                {include file="crud/list/search.tpl"}
            {/if}
        </div>
    </div>
    <div class="row">
        <div class="col d-flex mb-3">
            {include file="crud/list/actions.tpl"}
        </div>
    </div>
    <div class="row">
        <div class="col-lg-5">
            {include file="crud/list/multi.tpl"}
        </div>
    </div>
    <form method="post" id="list-form">
        <div class="table-responsive mb-2">
            <table class="table table-striped table-bordered table-hover table-responsive">
                <thead>
                <tr>
                    <th class="col-lg-1">
                    </th>
                    <th class="col-lg-1 fs14 text-nowrap">
                        <button type="button" class="btn btn-light" id="expand-header">
                            <span class="bi bi-plus" aria-hidden="true"></span>
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
                        <th class="fs14 col-lg-{$colWidth} {if isset($data.sort[$fieldName])}bg-info bg-opacity-25{/if}" {if !empty($field.description)} title="{$field.description|htmlspecialchars}"{/if}>
                            <div class="d-flex justify-content-center align-items-center text-nowrap
Создать новую выгрузку">
                                <span>{$field.name|htmlspecialchars}</span>
                                {if isset($field.role.sortable) && $field.role.sortable}
                                    <a class="fs14"
                                       href="{$_application->getUrl($_route->getName(), ['q' => $data.query, 'sort' => [$fieldName => $sort], 'limit' => $data.items->getCurrentLimit(), 'offset' => $data.items->getCurrentSkip() ])}">
                                        <i class="bi bi-caret-{if $sort == -1}down{else}up{/if}"></i>
                                    </a>
                                {/if}
                            </div>
                        </th>
                    {/foreach}
                </tr>
                </thead>
                <tbody>
                {foreach from=$data.items item="item" name="itemsForeach"}
                    <tr>
                        <td>
                            <div class="form-check">
                                <input type="checkbox"
                                       value="{$item->getIdValue()}"
                                       id="list-checkbox-{$smarty.foreach.itemsForeach.iteration}"
                                       name="items[]"
                                       class="chkbox form-check-input">
                            </div>
                        </td>
                        <td>
                            <ul>
                                {assign var="structure" value=$_entity->getModule()->getModuleRoutes()->getStructure()}
                                {foreach from=$structure[$_entity->getEntityUniqueName()]['actions'] key="routeName" item="routeAction"}
                                    {if $routeAction->getType() != 2}{continue}{/if}
                                    <li class="fs14">
                                        <a class="fs14"
                                           href="{$_slim->urlFor($routeName, [$_entity->getMapper()->getKey() => $item->getIdValue()])}">
                                            <span>{$routeAction->getName()|htmlspecialchars}</span>
                                        </a>
                                    </li>
                                {/foreach}
                            </ul>
                        </td>
                        {foreach from=$map key="fieldName" item="field"}
                            <th class="fs14">
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
        </div>
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
    <script src="/custom/sds/crud/list_tpl/script.js"></script>
{/if}