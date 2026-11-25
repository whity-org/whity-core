<?php

declare(strict_types=1);

namespace Whity\Sdk\Sync;

/**
 * Optional (SDK 1.173, WHIT-1422): which of your routes speak the sync wire
 * contract, so Whity Desktop keeps that data in two places, the person's
 * computer and the workspace, and carries changes both ways.
 *
 * ```php
 * public function getSyncResources(): array
 * {
 *     return ['/api/demo-catalog/items'];
 * }
 * ```
 *
 * Each entry is the path you registered the resource's routes under, as
 * written in {@see \Whity\Sdk\PluginInterface::getRoutes()} (`/api/...`,
 * without the version prefix). Behind that path must stand a
 * {@see SyncController} over a {@see SyncableResource}: `GET` with
 * `?updatedSince=<cursor>` is the changes feed, `POST` creates idempotently
 * on `clientUuid`, `PATCH` and `DELETE` on `{path}/{id}` take `If-Match`.
 * The desktop PHP host serves the same plugin on the device, so the path is
 * the same on both ends; the relay in the app reads each end's feed and
 * writes the other end, and parks a row both ends changed for a person to
 * resolve (whity-desktop, ADR 0001, decision 4).
 *
 * On the server this declaration does nothing. A plugin that does not
 * implement it keeps its data on the server only. A path that is not one of
 * the plugin's own `GET` and `POST` routes is dropped with a logged warning.
 */
interface PluginSyncResourcesInterface
{
    /**
     * @return list<string> Route paths, `/api/...`, each the base of a SyncController.
     */
    public function getSyncResources(): array;
}
