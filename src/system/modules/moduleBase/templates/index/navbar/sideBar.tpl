{assign var="menu" value=$_application->getMenuStructure()}


<div class="navbar-light sidebar" role="navigation">
    <div class="sidebar-nav navbar-collapse d-flex flex-column mb-5">
        <div class="input-group bg-light input-group-sm">
            <input type="text"
                   class="form-control search-field m-1 rounded-1 p-2 p-lg-1"
                   placeholder="Фильтрация меню"
                   id="side-menu-filter">
        </div>
        <ul class="nav d-flex flex-column w-100 menu-item border-1 border-light" id="side-menu">
            {foreach from=$menu item='menuItem'}
                {include file="index/navbar/sideBar.menuItem.tpl" item=$menuItem}
            {/foreach}
        </ul>
    </div>
</div>


<script>
  $("#side-menu").metisMenu({
    toggle: false
  });
</script>

<script src="/custom/sds/navbar/sidebar_tpl/filterMenu.js"></script>