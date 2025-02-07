{if $status === false}
    <div class="alert alert-danger">
        <h4>Ошибка!{if isset($error_code)} <small>#{$error_code}</small>{/if}</h4>
        <pre><code>{$data.errors|json_encode:384}</code></pre>
        {if isset($file, $line)}
        <small>В файле {$file}:{$line}</small>
        {/if}
        {if isset($trace)}
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">
                    <a data-bs-toggle="collapse" href="#collapseOne" aria-expanded="false" class="collapsed">Trace</a>
                </h4>
            </div>
            <div id="collapseOne" class="panel-collapse collapse" aria-expanded="false" style="height: 0px;">
                <div class="card-body">
                    <pre><code>{$trace}</code></pre>
                </div>
            </div>
        </div>
        {/if}
    </div>
{elseif $status === true && isset($response_code)}
    <div class="alert alert-success">
        <h4>OK!{if isset($response_code)} <span>#{$response_code}</span>{/if}
        <small>
        {if isset($data.item)}
            {$i18n->get($response_code, $data.item->getTitleValue())}
        {else}
            {$i18n->get($response_code)}
        {/if}
        </small>
        </h4>
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">
                    <a data-bs-toggle="collapse" href="#collapseOne" aria-expanded="false" class="collapsed"><small>Дополнительная информация</small></a>
                </h4>
            </div>
            <div id="collapseOne" class="panel-collapse collapse" aria-expanded="false" style="height: 0px;">
                <div class="card-body">
                    <pre><code>{$data|json_encode:384}</code></pre>
                </div>
            </div>
        </div>
    </div>
{/if}