<?php

namespace App;

/**
 * Thrown after an error response has already been emitted for the page; the
 * page script should catch it and stop rendering.
 */
final class PageAborted extends \RuntimeException
{
}
