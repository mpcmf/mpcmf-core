{if !isset($maxRows)}
    {assign var="maxRows" value=10}
{/if}
{if !isset($minRows)}
    {assign var="minRows" value=3}
{/if}
{if isset($item)}
    {assign var="value" value=$item->getFieldValue($fieldName)}
    {if is_array($value)}
        {assign var="value" value=$value|json_encode:384}
    {/if}
{else}
    {assign var="value" value=""}
{/if}
{assign var="rows" value=substr_count($value, "\n")}
{if $rows > $maxRows}
    {assign var="rows" value=$maxRows}
{elseif $rows < $minRows}
    {assign var="rows" value=$minRows}
{/if}
<textarea
        id="item-{$fieldName}"
        name="item[{$fieldName}]"
        class="form-control"
        rows="5"
        {if isset($readonly) && $readonly} readonly{/if}
        {if isset($field.options.required) && $field.options.required} required{/if}
        {if isset($field.role.key, $field.role['generate-key']) && $field.role.key && $field.role['generate-key']} disabled{/if}
        >{$value|htmlspecialchars}</textarea>