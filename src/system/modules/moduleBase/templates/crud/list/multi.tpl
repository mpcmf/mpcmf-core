<ul class="pagination">
    <li class="paginate_button multi" tabindex="0">
        <a href="{$_slim->urlFor("`$_entity->getEntityUniqueName()`/crud.multiUpdate")}">
            Мультиредактирование
        </a>
    </li>
    <li class="paginate_button multi" tabindex="0">
        <a href="{$_slim->urlFor("`$_entity->getEntityUniqueName()`/crud.multiRemove")}">
            Мультиудаление
        </a>
    </li>
</ul>