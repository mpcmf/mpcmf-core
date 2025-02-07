<ul class="pagination mb-3 flex-wrap gap-1">
    <li class="paginate_button multi me-2" tabindex="0">
        <a class="page-link rounded-2" href="{$_slim->urlFor("`$_entity->getEntityUniqueName()`/crud.multiUpdate")}">
            Мультиредактирование
        </a>
    </li>
    <li class="paginate_button multi" tabindex="0">
        <a class="page-link rounded-2" href="{$_slim->urlFor("`$_entity->getEntityUniqueName()`/crud.multiRemove")}">
            Мультиудаление
        </a>
    </li>
</ul>