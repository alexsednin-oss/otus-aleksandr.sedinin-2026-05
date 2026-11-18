<?php

namespace Alex\Autoservice\Service;

/**
 * Отказ в доступе к данным проекта.
 *
 * Отдельный тип нужен, чтобы RestService отдавал наружу код ACCESS_DENIED,
 * а не общий ERROR_AUTOSERVICE.
 */
class AccessDeniedException extends \RuntimeException
{
}
