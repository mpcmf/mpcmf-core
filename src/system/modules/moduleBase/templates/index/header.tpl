<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
    {assign var="title" value=$_application->getTitle()}
    <title>MPCMF Администрирование{if isset($title) && !empty($title)} / {$title}{/if}</title>


    <link rel="stylesheet" href="/sources/dateTimePicker/css/tempus-dominus.min.css">
    <script src="/sources/popper/popper.min.js"></script>
    <script src="/sources/dateTimePicker/js/tempus-dominus.js"></script>

    <link rel="stylesheet" href="/sources/icons.css">

    <link rel="stylesheet" href="/sources/index.css">
    <link rel="stylesheet" href="/sources/jquery.css">

    <script src="/sources/jquery.bundle.js"></script>
    <script src="/sources/index.bundle.js"></script>

    <script src="/sources/plugins.bundle.js"></script>
    <script defer src="/sources/moment.bundle.js"></script>


    <link href="/custom/startbootstrap-sb-admin-2/dist/css/sb-admin-2.css" rel="stylesheet">
    <script defer src="/custom/startbootstrap-sb-admin-2/dist/js/sb-admin-2.js"></script>

    <script defer type="text/javascript" src="/custom/mpcmf/sds.js?d=07-09-2017"></script>

    <link rel="stylesheet" href="/custom/mpcmf/sds-support.css?d=16-03-2023"/>
    <link rel="stylesheet" href="/custom/mpcmf/sds-blue.css?d=14-07-2017" />
//
    <link href="/custom/select2/dist/css/select2.css" rel="stylesheet" />
    <script src="/custom/select2/dist/js/select2.full.js"></script>

    <link rel="stylesheet" href="/custom/mpcmf/customized.css?d=09-07-2020" />
</head>
<body>