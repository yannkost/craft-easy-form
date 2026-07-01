<?php

namespace yannkost\easyform\controllers;

/**
 * Shared page-size handling for the CP list controllers (submissions, forms,
 * exports). Keeps the allowed sizes in one place so the "Per page" dropdown and
 * the server-side clamp can never drift apart.
 */
trait PaginatesTrait
{
    /** Page sizes offered in the CP "Per page" dropdown. */
    public const PAGE_SIZES = [10, 20, 50, 100, 500];

    /** Default page size when none (or an unknown one) is requested. */
    public const DEFAULT_PAGE_SIZE = 20;

    /**
     * Resolve a requested page size to one of the whitelisted values, falling
     * back to the default. Whitelisting (rather than clamping) stops a crafted
     * ?limit=999999 from turning a paginated list into an unbounded query.
     */
    protected static function resolvePageSize($requested): int
    {
        $requested = (int) $requested;
        return in_array($requested, self::PAGE_SIZES, true) ? $requested : self::DEFAULT_PAGE_SIZE;
    }
}
