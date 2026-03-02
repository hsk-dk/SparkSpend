<?php
require 'includes/configuration.php';
require 'includes/DatabaseManager.php';
require 'includes/QueryBuilder.php';
?>
<!DOCTYPE html>
<html lang="da">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ladninger</title>
  <link rel="icon" type="image/svg+xml" href="includes/favicon.svg">
  <script src="includes/jordvarme.js"></script>
  <!-- Material Symbols -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <!-- Flatpickr CSS og JS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/da.js"></script>
  
  <!-- Bootstrap CSS og JS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
   <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
  <link rel="stylesheet" href="includes/style.css">

  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
  <script src="includes/app.js"></script>

</head>
<body>
  <!-- Modern Tab-Based Navigation Header -->
  <header>
    <div class="header-left">
      <div class="d-flex align-items-center gap-2">
        <div style="width: 48px; height: 48px;">
          <img src="images/logo.svg" width="48" height="48" alt="SparkSpend Logo">
        </div>
        <h1>SparkSpend</h1>
      </div>
    </div>
    
    <!-- Main Navigation Tabs -->
    <nav class="header-nav">
      <button class="header-tab-button active" data-target="charges-section" aria-label="Ladninger tab">
        <i class="fas fa-battery-three-quarters"></i> Ladninger
      </button>
      <button class="header-tab-button" data-target="analytics-section" aria-label="Analyse tab">
        <i class="fas fa-chart-line"></i> Analyse
      </button>
      <button class="header-tab-button" data-target="settings-section" aria-label="Indstillinger tab">
        <i class="fas fa-cog"></i> Indstillinger
      </button>
    </nav>
    
    <!-- Create Charge Button -->
    <div class="header-right">
      <button type="button" class="btn btn-primary button-create-charge" data-bs-toggle="modal" data-bs-target="#createExChargeModal" aria-label="Opret ny ladning">
        <i class="fas fa-plus"></i> <span class="button-label">Ny Ladning</span>
      </button>
    </div>
  </header>

  <!-- CHARGES SECTION - Primary Tab -->
  <section id="charges-section" class="tab-section active">
