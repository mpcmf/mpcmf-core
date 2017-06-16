<div class="row">
    <div class="col-lg-12">

        <div id="{$fieldName}" class="carousel slide" data-ride="carousel">
            <!-- Indicators -->
            <ol class="carousel-indicators">
                {foreach from=$items key="key" item="item" name="slider"}
                    {if $smarty.foreach.slider.index == 0}
                        <li data-target="#{$fieldName}" data-slide-to="{$smarty.foreach.slider.index}" class="active"></li>
                    {else}
                        <li data-target="#{$fieldName}" data-slide-to="{$smarty.foreach.slider.index}"></li>
                    {/if}
                {/foreach}
            </ol>

            <!-- Wrapper for slides -->
            <div class="carousel-inner" role="listbox">
                {foreach from=$items key="key" item="item" name="carousel"}
                    {if $smarty.foreach.carousel.index == 0}
                        <div class="item active">
                            <img src="{$item}" alt="{$key}">
                        </div>
                    {else}
                        <div class="item">
                            <img src="{$item}" alt="{$key}">
                        </div>
                    {/if}
                {/foreach}
            </div>

            <!-- Left and right controls -->
            <a class="left carousel-control" href="#{$fieldName}" role="button" data-slide="prev">
                <span class="glyphicon glyphicon-chevron-left" aria-hidden="true"></span>
                <span class="sr-only">Previous</span>
            </a>
            <a class="right carousel-control" href="#{$fieldName}" role="button" data-slide="next">
                <span class="glyphicon glyphicon-chevron-right" aria-hidden="true"></span>
                <span class="sr-only">Next</span>
            </a>
        </div>

    </div>
</div>