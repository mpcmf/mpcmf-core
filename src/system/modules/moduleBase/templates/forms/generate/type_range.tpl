{if isset($minVal)}
    {assign var="min" value=$minVal}
{else}
    {assign var="min" value=0}
{/if}

{if isset($maxVal)}
    {assign var="max" value=$maxVal}
{else}
    {assign var="max" value=100}
{/if}

{if isset($val)}
    {assign var="value" value=$val}
{else}
    {assign var="value" value=50}
{/if}

<div class="form-group">
    <label for="{$fieldName}">{$fieldName}</label>
    <div class="row">
        <div class="col-md-11">
            <input type="range" id="{$fieldName}" min="{$min}" max="{$max}" value="{$value}"/>
        </div>
        <div class="col-md-1">
            <span id="{$fieldName}-value">{$value}</span>
        </div>
    </div>
</div>

<script>
    $('#{$fieldName}').on('change', function(e){
        console.log(e.target.value);
        $('#{$fieldName}-value').text(e.target.value);
    });
</script>


