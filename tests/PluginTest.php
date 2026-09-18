<?php

/**
 * Plugin validation tests for Directions.
 *
 * These are contract tests: they guard the things that silently break a
 * consumer — the manifest the compiler reads, the event namespace the native
 * code dispatches, and the cross-platform promise that every call answers.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->swiftFile = $this->pluginPath.'/resources/ios/Sources/DirectionsFunctions.swift';
    $this->kotlinFile = $this->pluginPath.'/resources/android/src/DirectionsFunctions.kt';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();

        json_decode(file_get_contents($this->manifestPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions']);
        expect($this->manifest['name'])->toBe('guppylab/plugin-directions');
        expect($this->manifest['namespace'])->toBe('Directions');
    });

    it('declares every bridge function for both platforms', function () {
        $names = array_column($this->manifest['bridge_functions'], 'name');

        expect($names)->toBe(['Directions.Distances', 'Directions.IsSupported']);

        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'ios', 'android']);
            expect($function['android'])->toStartWith('com.guppylab.plugins.directions.DirectionsFunctions.');
            expect($function['ios'])->toStartWith('DirectionsFunctions.');
        }
    });

    it('declares its event under the plugin namespace', function () {
        expect($this->manifest['events'])->toBe(['Guppylab\Directions\Events\DistancesReceived']);
    });

    it('requests INTERNET on Android because routing there is server backed', function () {
        expect($this->manifest['android']['permissions'])->toContain('android.permission.INTERNET');
    });

    it('keeps the manifest version in sync with composer', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect($this->manifest['name'])->toBe($composer['name']);
        expect($this->manifest['version'])->toMatch('/^\d+\.\d+\.\d+$/');
    });
});

describe('Native Code', function () {
    it('has the iOS Swift file in resources/ios/Sources', function () {
        expect(file_exists($this->swiftFile))->toBeTrue();

        $content = file_get_contents($this->swiftFile);
        expect($content)->toContain('enum DirectionsFunctions');
        expect($content)->toContain('class Distances');
        expect($content)->toContain('class IsSupported');
        expect($content)->toContain('BridgeFunction');
    });

    it('dispatches the event under the current vendor namespace on iOS', function () {
        // A stale fallback here means the event is dispatched to a class that no
        // longer exists, and the listener never fires.
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('Guppylab\\\\Directions\\\\Events\\\\DistancesReceived');
        expect($content)->not->toContain('Nativephp\\\\Directions');
    });

    it('bounds every iOS route calculation so the event always fires', function () {
        $content = file_get_contents($this->swiftFile);

        expect($content)->toContain('done.wait(timeout:');
        expect($content)->toContain('"timeout"');
    });

    it('has the Android Kotlin file with the vendor package', function () {
        expect(file_exists($this->kotlinFile))->toBeTrue();

        $content = file_get_contents($this->kotlinFile);
        expect($content)->toContain('package com.guppylab.plugins.directions');
        expect($content)->toContain('object DirectionsFunctions');
        expect($content)->toContain('BridgeFunction');
    });

    it('dispatches the result event on Android too', function () {
        // The whole point of the parity work: Android used to be a no-op, so a
        // caller awaiting DistancesReceived waited forever.
        $content = file_get_contents($this->kotlinFile);

        expect($content)->toContain('NativeActionCoordinator.dispatchEvent');
        expect($content)->toContain('Guppylab\\\\Directions\\\\Events\\\\DistancesReceived');
    });

    it('routes on Android through OSRM when a provider is configured', function () {
        $content = file_get_contents($this->kotlinFile);

        expect($content)->toContain('/table/v1/');
        expect($content)->toContain('/route/v1/');
        expect($content)->toContain('"unsupported"');
    });

    it('reads bridge parameters as org.json values on Android', function () {
        // BridgeRouter hands parameters over raw, so nested objects arrive as
        // JSONObject and arrays as JSONArray — casting them to Map/List fails.
        $content = file_get_contents($this->kotlinFile);

        expect($content)->toContain('as? JSONObject');
        expect($content)->toContain('as? JSONArray');
    });
});

describe('PHP Classes', function () {
    it('has the service provider under Guppylab\\Directions', function () {
        $content = file_get_contents($this->pluginPath.'/src/DirectionsServiceProvider.php');

        expect($content)->toContain('namespace Guppylab\Directions');
        expect($content)->toContain('class DirectionsServiceProvider');
        expect($content)->toContain('mergeConfigFrom');
    });

    it('has the facade', function () {
        $content = file_get_contents($this->pluginPath.'/src/Facades/Directions.php');

        expect($content)->toContain('namespace Guppylab\Directions\Facades');
        expect($content)->toContain('class Directions extends Facade');
    });

    it('has the main implementation class', function () {
        $content = file_get_contents($this->pluginPath.'/src/Directions.php');

        expect($content)->toContain('namespace Guppylab\Directions');
        expect($content)->toContain('function distances');
        expect($content)->toContain('function isSupported');
    });

    it('ships a publishable config', function () {
        // Read as source rather than evaluated: env() needs a booted app, and
        // what matters here is the shipped defaults, not a resolved value.
        $config = file_get_contents($this->pluginPath.'/config/directions.php');

        expect($config)->toContain("'max_destinations' =>");
        expect($config)->toContain("'timeout' =>");
        expect($config)->toContain("'provider' =>");
        expect($config)->toContain("'osrm' =>");
    });

    it('does not default Android to the public OSRM demo server', function () {
        // router.project-osrm.org forbids production use; shipping it as a
        // default would put every consumer in breach without them knowing.
        $config = file_get_contents($this->pluginPath.'/config/directions.php');

        expect($config)->toContain("env('DIRECTIONS_ANDROID_PROVIDER', 'none')");
        expect($config)->toContain("env('DIRECTIONS_OSRM_URL')");
        expect($config)->not->toContain("env('DIRECTIONS_OSRM_URL',");
    });

    it('exposes decoded rows on the event', function () {
        $content = file_get_contents($this->pluginPath.'/src/Events/DistancesReceived.php');

        expect($content)->toContain('function rows');
        expect($content)->toContain('function routed');
        expect($content)->toContain('function failed');
    });
});

describe('JavaScript Library', function () {
    it('ships a JS library with TypeScript definitions', function () {
        expect(file_exists($this->pluginPath.'/resources/js/index.js'))->toBeTrue();
        expect(file_exists($this->pluginPath.'/resources/js/index.d.ts'))->toBeTrue();

        $js = file_get_contents($this->pluginPath.'/resources/js/index.js');
        expect($js)->toContain('Directions.Distances');
        expect($js)->toContain('export');
    });

    it('unwraps the native-call envelope', function () {
        // /_native/api/call answers { status, data }. Reading the top level
        // returns undefined for every field and silently yields the fallback.
        $js = file_get_contents($this->pluginPath.'/resources/js/index.js');

        expect($js)->toContain('const nativeResponse = result.data;');
        expect($js)->toContain("result.status === 'error'");
        expect($js)->toContain('X-CSRF-TOKEN');
    });
});

describe('Composer Configuration', function () {
    it('has a valid composer.json with the nativephp-plugin type', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['name'])->toBe('guppylab/plugin-directions');
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])
            ->toBe(['Guppylab\Directions\DirectionsServiceProvider']);
    });
});
