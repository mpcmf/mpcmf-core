<link href="/bower_components/leaflet/dist/leaflet.css" rel="stylesheet">
<link href="/bower_components/leaflet-draw/dist/leaflet.draw.css" rel="stylesheet">
<link href="/bower_components/CodeMirror/lib/codemirror.css" rel="stylesheet">
<link href="/bower_components/CodeMirror/theme/eclipse.css" rel="stylesheet">
<link href="/bower_components/CodeMirror/addon/lint/lint.css" rel="stylesheet">
<link href="/custom/map.edit/geoJson.css" rel="stylesheet">

<script src="/bower_components/leaflet/dist/leaflet-src.js"></script>
<script src="/bower_components/leaflet-draw/dist/leaflet.draw-src.js"></script>

<script src="/bower_components/CodeMirror/lib/codemirror.js"></script>
<script src="/bower_components/CodeMirror/addon/lint/lint.js"></script>
<script src="/bower_components/CodeMirror/addon/lint/json-lint.js"></script>
<script src="/bower_components/CodeMirror/mode/javascript/javascript.js"></script>
<script src="/custom/geojsonhint/geojsonhint.js"></script>
<script src="/custom/map.edit/geoJson-funcs.js"></script>

{if isset($item)}
    {assign var="value" value=$item->getFieldValue($fieldName)}
    {if !is_string($value)}
        {assign var="value" value=$value|json_encode:384}
    {/if}
{else}
    {assign var="value" value=""}
{/if}
<script>
    $(document).ready(function () {
        {if isset($readonly) && $readonly}
            geoJsonMap('{$fieldName}', true);
        {else}
            geoJsonMap('{$fieldName}', false);
        {/if}
    });
</script>
<div id={$fieldName} class="map-container">
    <div class="left-map-panel">
        <div id='map-{$fieldName}' class="map"></div>
    </div>
    <div class="right-map-panel">
<textarea
        id="item-{$fieldName}"
        class="map-textarea"
        {if isset($field.options.required) && $field.options.required} required{/if}
        name="item[{$fieldName}]"
        >{$value|htmlspecialchars}</textarea>

</div>
    <div style="clear: both"></div>
</div>