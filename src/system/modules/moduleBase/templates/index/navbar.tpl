<!-- Navigation -->
<nav class="navbar navbar-default navbar-static-top" role="navigation" style="margin-bottom: 0">
    <div class="navbar-header">
        <a class="navbar-brand" href="/">MPCMF Admin{if isset($_title) && !empty($_title)}: {$_title}{/if}</a>
    </div>
    <!-- /.navbar-header -->

    {include file="./navbar/topPanel.tpl"}

    {include file="./navbar/sideBar.tpl"}
</nav>