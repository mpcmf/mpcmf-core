{if !isset($queryParams)}
    {assign var="queryParams" value=['q' => $data.query, 'sort' => $data.sort]}
{/if}

{if isset($newPagination)}
    {assign var="countPages" value=ceil($data.items->count()/$data.items->getCurrentLimit())}
    {assign var="currentPage" value=($data.items->getCurrentSkip()/$data.items->getCurrentLimit()) + 1}
    {if $currentPage <= 0}
        {assign var="currentPage" value=1}
    {/if}

    {assign var="firstInPaginatiorPageNum" value=$currentPage - 2}
    {if $firstInPaginatiorPageNum <= 0}
        {assign var="firstInPaginatiorPageNum" value=$currentPage - 1}
    {/if}
    {if $firstInPaginatiorPageNum <= 0}
        {assign var="firstInPaginatiorPageNum" value=$currentPage}
    {/if}

    {assign var="lastInPaginatiorPageNum" value=$currentPage + 2}
    {if $lastInPaginatiorPageNum > $countPages}
        {assign var="lastInPaginatiorPageNum" value=$currentPage + 1}
    {/if}
    {if $lastInPaginatiorPageNum > $countPages}
        {assign var="lastInPaginatiorPageNum" value=$currentPage}
    {/if}

   <nav class="d-flex">
        {if $currentPage > 3}
        <ul class="pagination flex-wrap">
            <li><a class="page-link rounded-start" href="{$_application->getUrl($_route->getName(), $queryParams)}">В начало</a></li>
        </ul>
        {/if}
       <ul class="pagination">
        {for $page=$firstInPaginatiorPageNum to $currentPage - 1}
            <li><a class="page-link" href="{$_application->getUrl($_route->getName(), array_merge($queryParams, ['offset' => $data.items->getCurrentLimit() * ($page-1), 'limit' => $data.items->getCurrentLimit()]))}">{$page}</a></li>
        {/for}
           <li class="active"><a class="page-link" href="#">{$currentPage}</a></li>
           {for $page=$currentPage + 1 to $lastInPaginatiorPageNum}
               <li><a class="page-link" href="{$_application->getUrl($_route->getName(), array_merge($queryParams, ['offset' => $data.items->getCurrentLimit() * ($page-1), 'limit' => $data.items->getCurrentLimit()]))}">{$page}</a></li>
           {/for}
    </ul>
    <ul class="pagination flex-wrap">
        {assign var="nextPage" value=$currentPage+1}
        {if $nextPage > $countPages}
            <li class="disabled"><a class="page-link rounded-end" href="">Следующая</a></li>
        {else}
            <li><a class="page-link rounded-end" href="{$_application->getUrl($_route->getName(), array_merge($queryParams, ['offset' => $data.items->getCurrentLimit() * ($nextPage - 1), 'limit' => $data.items->getCurrentLimit()]))}">Следующая</a></li>
        {/if}
    </ul>
   </nav>
{else}
    <ul class="pagination mb-1 flex-wrap">
        <li class="paginate_button previous{if !$data.items->hasPrevSkip()} disabled{/if}" tabindex="0">
            {assign var="prevParams" value=array_merge($queryParams, ['limit' => $data.items->getCurrentLimit(), 'offset' => $data.items->getPrevSkip()])}
            <a class="page-link rounded-start"{if $data.items->hasPrevSkip()} href="{$_application->getUrl($_route->getName(), $prevParams)}"{/if}>
                Предыдущая
            </a>
        </li>
        <li class="paginate_button current">
            <a class="page-link">
                {($data.items->getCurrentSkip()/$data.items->getCurrentLimit()) + 1}/{($data.items->count()/$data.items->getCurrentLimit())|ceil}
            </a>
        </li>
        <li class="paginate_button next{if !$data.items->hasNextSkip()} disabled{/if}" tabindex="0">
            {assign var="nextParams" value=array_merge($queryParams, ['limit' => $data.items->getCurrentLimit(), 'offset' => $data.items->getNextSkip()])}
            <a class="page-link rounded-end"{if $data.items->hasNextSkip()} href="{$_application->getUrl($_route->getName(), $nextParams)}"{/if}>
                Следующая
            </a>
        </li>
    </ul>
    <div class="small text-muted mb-3">Всего элементов: {$data.items->count()}</div>
{/if}
