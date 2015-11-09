<ul class="pagination">
    {assign var="structure" value=$_entity->getModule()->getModuleRoutes()->getStructure()}
    {foreach from=$structure[$_entity->getEntityUniqueName()]['actions'] key="routeName" item="routeAction"}
        {if $routeAction->getType() != 1}{continue}{/if}
        <li class="paginate_button" tabindex="0">
            <a href="{$_slim->urlFor($routeName)}">
                {$routeAction->getName()}
            </a>
        </li>
    {/foreach}
</ul>