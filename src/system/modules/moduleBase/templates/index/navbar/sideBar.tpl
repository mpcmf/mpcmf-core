{assign var="menu" value=$_application->getMenuStructure()}
<div class="navbar-default sidebar" role="navigation">
    <div class="sidebar-nav navbar-collapse">
        <ul class="nav" id="side-menu">
        {foreach from=$menu item='menuItem'}
            {include file="index/navbar/sideBar.menuItem.tpl" item=$menuItem}
        {/foreach}
        </ul>
    </div>
</div>