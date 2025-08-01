# SparkSpend

A web-based dashboard for tracking electric vehicle charging costs and heat pump efficiency using the Monta API.

![SparkSpend Dashboard](images/logo.svg)

## Features

- 📊 Track electric vehicle charging sessions and costs
- 🔥 Monitor heat pump performance and efficiency
- 📈 Visualize energy consumption with interactive charts
- 💰 Calculate charging costs and energy expenses
- 🚗 Manage multiple vehicles and charging locations
- 📱 Responsive design for mobile and desktop

## Requirements

- PHP 7.4 or higher
- SQLite3 extension for PHP
- Web server (Apache, Nginx, or PHP built-in server)
- Monta API credentials

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/sparkspend.git
   cd sparkspend
   ```

2. **Configure environment variables**
   ```bash
   cp .env.example .env
   ```
   
   Edit `.env` and add your Monta API credentials:
   ```
   MONTA_CLIENT_ID=your_actual_client_id
   MONTA_CLIENT_SECRET=your_actual_client_secret
   ```

3. **Set up the database**
   
   Run the setup script to create the necessary database tables:
   ```bash
   php setup.php
   ```

4. **Configure web server**
   
   Point your web server to the project directory, or use PHP's built-in server for development:
   ```bash
   php -S localhost:8000
   ```

5. **Access the application**
   
   Open your browser and navigate to `http://localhost:8000`

## Configuration

### Monta API Setup

1. Visit the [Monta Developer Portal](https://developer.monta.com/)
2. Create an account and generate API credentials
3. Add your credentials to the `.env` file

### Database Setup

The application uses SQLite for data storage. The database files are automatically created in the `data/` directory when you run the setup script.

## Usage

### Vehicle Charging

- View charging sessions and costs
- Add external charging records
- Categorize charges by type
- Monitor charging efficiency

### Heat Pump Monitoring

- Track heat pump performance
- Monitor energy consumption
- View efficiency statistics

## API Endpoints

The application provides several API endpoints for data management:

- `getCharges.php` - Retrieve charging data
- `getVehicles.php` - Get vehicle information
- `getHeatpumpData.php` - Heat pump statistics
- `createExCharge.php` - Add external charges
- `updateCategory.php` - Update charge categories

## Cron Jobs

Set up the following cron job to automatically sync data from Monta:

```bash
# Run every hour to sync charging data
0 * * * * php /path/to/sparkspend/cron/update_monta_data.php
```

## File Structure

```
sparkspend/
├── data/                   # Database files
├── includes/              # Configuration and assets
│   ├── configuration.php  # Main configuration
│   ├── style.css         # Application styles
│   └── jordvarme.js      # JavaScript functionality
├── images/               # Images and logos
├── cron/                 # Scheduled tasks
└── backup/               # Backup files
```

## Security Considerations

- **Never commit `.env` files** - They contain sensitive API credentials
- **Database files** are excluded from version control
- **API credentials** are loaded from environment variables
- Consider setting up **HTTPS** for production deployments

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

If you encounter any issues or have questions, please [open an issue](https://github.com/yourusername/sparkspend/issues) on GitHub.

## Acknowledgments

- [Monta](https://monta.com/) for providing the charging station API
- [Chart.js](https://chartjs.org/) for data visualization
- [Bootstrap](https://getbootstrap.com/) for UI components
