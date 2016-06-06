<form action="{$_slim->urlFor($_route->getName())}" method="get" role="form">
    <div class="form-group input-group" style="margin: 20px 0">
        <input name="q" id="list-search" type="text" class="form-control" value="{$data.query|htmlentities}">
        <span class="input-group-btn">
            <button class="btn btn-default" type="submit"><i class="fa fa-search"></i></button>
        </span>
    </div>
</form>