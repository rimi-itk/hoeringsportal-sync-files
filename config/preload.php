<?php

/*
 * This file is part of hoeringsportal-sync-files.
 *
 * (c) 2018–2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

if (file_exists(dirname(__DIR__).'/var/cache/prod/srcApp_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/srcApp_KernelProdContainer.preload.php';
}

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
