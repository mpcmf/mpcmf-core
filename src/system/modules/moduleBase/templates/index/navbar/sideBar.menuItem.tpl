{assign var="hasSubItems" value=isset($item['subitems']) && count($item['subitems'])}
<li{if isset($item['active']) && $item['active']} class="active"{/if}>
    <a href="{$item['path']}">
        <i class="fa {if $hasSubItems}fa-folder-o{else}fa-cube fa-fw{/if}"></i> {$item['name']}{if $hasSubItems}<span class="fa arrow"></span>{/if}
    </a>
{if $hasSubItems}
    <ul class="nav nav-second-level">
        {foreach from=$item['subitems'] item='subItem'}
            {include file="index/navbar/sideBar.menuItem.tpl" item=$subItem}
        {/foreach}
    </ul>
{/if}
</li>