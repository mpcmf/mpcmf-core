<?php

namespace mpcmf\system\helper\io;

/**
 * System codes
 *
 * @author Gregory Ostrovsky <greevex@gmail.com>
 */
class codes {

    /**
     * mpcmf codes
     */
    const RESPONSE_CODE_FAIL = 0;
    const RESPONSE_CODE_OK = 1;
    const RESPONSE_CODE_SAVED = 2;
    const RESPONSE_CODE_REMOVED = 3;
    const RESPONSE_CODE_CREATED = 4;
    const RESPONSE_CODE_DUPLICATE = 5;
    const RESPONSE_CODE_UNKNOWN_FIELD = 6;
    const RESPONSE_CODE_NOT_FOUND = 7;

    const RESPONSE_CODE_FORM_FIELDS_ERROR = 200;

    const RESPONSE_CODE_DUPLICATE_STORAGE = 11000;


    /**
     * mprf-sds codes
     */
    const CODE_ERR_WTF                    = 0.0;

    const CODE_ERR_OBJECT_NOT_FOUND       = 50;

    const CODE_ERR_AUTH_REQUIRED          = 100;

    const CODE_ERR_METHOD_IS_DISABLED     = 200;
    const CODE_ERR_METHOD_NOT_IMPLEMENTED = 201;

    const CODE_ERR_MESSAGE_UNKNOWN_ERROR  = 300;
    const CODE_ERR_MESSAGE_ALREADY_EXISTS = 301;
    const CODE_ERR_MESSAGE_SAVE_ERROR     = 302;
    const CODE_ERR_MESSAGE_FORBIDEN       = 303;
    const CODE_ERR_MESSAGE_NOT_FOUND      = 304;
    const CODE_ERR_MESSAGE_RESOLVE_ID     = 305;

    const CODE_ERR_AUTHOR_UNKNOWN_ERROR   = 400;
    const CODE_ERR_AUTHOR_ALREADY_EXISTS  = 401;
    const CODE_ERR_AUTHOR_SAVE_ERROR      = 402;
    const CODE_ERR_AUTHOR_FORBIDEN        = 403;
    const CODE_ERR_AUTHOR_NOT_FOUND       = 404;
    const CODE_ERR_AUTHOR_RESOLVE_ID      = 405;

    const CODE_ERR_DB_TIMEOUT             = 500;

    const CODE_ERR_UNKNOWN_ERROR          = 600;
    const CODE_ERR_GENERATE_HASH          = 601;
    const CODE_ERR_INVALID_ARGUMENTS      = 602;
    const CODE_ERR_DATA_REQUIRED_FIELDS   = 603;
    const CODE_ERR_DATA_ALREADY_EXISTS    = 604;
    const CODE_ERR_DATA_SAVE_ERROR        = 605;
    const CODE_ERR_DATA_TOO_OLD           = 606;
    const CODE_ERR_DATA_NOT_FOUND         = 607;
    const CODE_ERR_DATA_RESOLVE_ID        = 608;
    const CODE_ERR_DATA_REMOVE            = 609;
    const CODE_ERR_DATA_UPDATE            = 610;
    const CODE_ERR_DATA_DENIED_BY_CONDITIONS = 611;

    const CODE_ERR_HUB_UNKNOWN_ERROR      = 700;
    const CODE_ERR_HUB_ALREADY_EXISTS     = 701;
    const CODE_ERR_HUB_SAVE_ERROR         = 702;
    const CODE_ERR_HUB_FORBIDEN           = 703;
    const CODE_ERR_HUB_NOT_FOUND          = 704;
    const CODE_ERR_HUB_RESOLVE_ID         = 705;
    const CODE_ERR_HUB_ASSIGN_ERROR       = 706;
    const CODE_ERR_HUB_UNASSIGN_ERROR     = 707;

    const CODE_ERR_MEDIA_UNKNOWN_ERROR    = 800;
    const CODE_ERR_MEDIA_ALREADY_EXISTS   = 801;
    const CODE_ERR_MEDIA_SAVE_ERROR       = 802;
    const CODE_ERR_MEDIA_FORBIDEN         = 803;
    const CODE_ERR_MEDIA_NOT_FOUND        = 804;
    const CODE_ERR_MEDIA_RESOLVE_ID       = 805;

    const CODE_ERR_GRAB_TEMPLATE_UNKNOWN_ERROR  = 900;
    const CODE_ERR_GRAB_TEMPLATE_ALREADY_EXISTS = 901;
    const CODE_ERR_GRAB_TEMPLATE_SAVE_ERROR     = 902;
    const CODE_ERR_GRAB_TEMPLATE_FORBIDEN       = 903;
    const CODE_ERR_GRAB_TEMPLATE_NOT_FOUND      = 904;
    const CODE_ERR_GRAB_TEMPLATE_RESOLVE_ID     = 905;

    const CODE_ERR_GRAB_BUILDER_UNKNOWN_ERROR  = 1000;
    const CODE_ERR_GRAB_BUILDER_ALREADY_EXISTS = 1001;
    const CODE_ERR_GRAB_BUILDER_SAVE_ERROR     = 1002;
    const CODE_ERR_GRAB_BUILDER_FORBIDEN       = 1003;
    const CODE_ERR_GRAB_BUILDER_NOT_FOUND      = 1004;
    const CODE_ERR_GRAB_BUILDER_RESOLVE_ID     = 1005;

    const CODE_ERR_KEYWORD_NOT_FOUND       = 1104;
    const CODE_ERR_KEYWORD_ASSIGN_ERROR    = 1106;
    const CODE_ERR_KEYWORD_UNASSIGN_ERROR  = 1107;

    const CODE_ERR_TOKEN_INVALID_HASH      = 2000;
    const CODE_ERR_TOKEN_EXPIRED           = 2000;

    const CODE_ERR_CATEGORY_UNKNOWN_ERROR  = 2100;
    const CODE_ERR_CATEGORY_ALREADY_EXISTS = 2101;
    const CODE_ERR_CATEGORY_SAVE_ERROR     = 2102;
    const CODE_ERR_CATEGORY_FORBIDEN       = 2103;
    const CODE_ERR_CATEGORY_NOT_FOUND      = 2104;
    const CODE_ERR_CATEGORY_RESOLVE_ID     = 2105;

    const CODE_ERR_RSS_UNKNOWN_ERROR  = 2200;
    const CODE_ERR_RSS_ALREADY_EXISTS = 2201;
    const CODE_ERR_RSS_SAVE_ERROR     = 2202;
    const CODE_ERR_RSS_FORBIDEN       = 2203;
    const CODE_ERR_RSS_NOT_FOUND      = 2204;
    const CODE_ERR_RSS_RESOLVE_ID     = 2205;
}