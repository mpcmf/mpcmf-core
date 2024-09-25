{assign var="hasSubItems" value=isset($item['subitems']) && count($item['subitems'])}
<li{if isset($item['active']) && $item['active']} class="mm-active"{/if}>
    <a href="{if !$hasSubItems}{$item['path']}{else}#{/if}" class="top-link side-menu-link {if $hasSubItems}has-arrow{/if}" aria-expanded="false">
        <span class="link-text">
            <i class="align-text-middle bi{if $item['path'] == "/"} bi bi-house-fill{elseif $hasSubItems} bi-folder{else} bi-layers-half{/if}"></i>
            <span>{$item['name']}</span>
        </span>
    </a>
{if $hasSubItems}
    <ul class="nav nav-{if isset($third) && $third}third{else}second{/if}-level menu-item {if isset($item['active']) && $item['active']}show{/if}">
        {foreach from=$item['subitems'] item='subItem'}
            {include file="index/navbar/sideBar.menuItem.tpl" item=$subItem third=true}
        {/foreach}
    </ul>
{/if}
</li>