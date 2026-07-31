<?php
/**
 * Documents the existing charges database schema.
 * Tables were originally created by the Monta integration setup.
 * Using IF NOT EXISTS so this is safe to run on existing databases.
 */
return [
    'description' => 'Initial charges database schema (vehicles, providers, charges, ext_charges, vehicle_charges)',
    'up' => [
        "CREATE TABLE IF NOT EXISTS vehicles (
            id INTEGER PRIMARY KEY,
            vehicleName TEXT
        )",
        "CREATE TABLE IF NOT EXISTS providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            providerName TEXT
        )",
        "CREATE TABLE IF NOT EXISTS charges (
            id INTEGER PRIMARY KEY,
            chargePointId INTEGER,
            createdAt TEXT,
            updatedAt TEXT,
            cablePluggedInAt TEXT,
            startedAt TEXT,
            stoppedAt TEXT,
            state TEXT,
            consumedKwh REAL,
            kwhLimit REAL,
            startMeterKwh REAL,
            endMeterKwh REAL,
            cost REAL,
            stopReason TEXT,
            socPercentage REAL,
            socLimit REAL,
            vehicleId INTEGER
        )",
        "CREATE TABLE IF NOT EXISTS ext_charges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicleId INTEGER,
            providerId INTEGER,
            kwh REAL,
            pris REAL,
            datetime TEXT
        )",
        "CREATE TABLE IF NOT EXISTS vehicle_charges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            vehicleId INTEGER,
            cablePluggedInAt TEXT,
            odometer REAL
        )",
    ],
];
