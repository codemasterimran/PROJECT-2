# DB Wizard - Database Management Tool

DB Wizard is a web-based database management tool that allows you to execute SQL files, manage database tables, and perform data operations with an intuitive interface.

## Features

- **SQL File Execution**: Execute SQL scripts to create and populate databases
- **Table Management**: View, create, and update database tables
- **Data Operations**:
  - Import/export data in CSV format
  - Create new records in bulk
  - Update existing records with complex conditions
- **User-Friendly Interface**: Intuitive UI with dropdowns and interactive forms
- **Alias Support**: Custom table and column name aliases for better readability

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL/MariaDB database
- Web server (Apache, Nginx, etc.)
- Composer (for dependency management)

### Setup Steps

1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/db-wizard.git
   cd db-wizard
Configure the database:

Create a new database in MySQL

Update the configuration in config.php:

php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'your_database');
define('BASE_DIR', '/path/to/your/installation/');
Set up table and column aliases (optional):

Edit the $tableAliases and $columnAliases arrays in config.php

Configure visible tables (optional):

Edit the $visibleTables array in config.php to specify which tables should be accessible

Place your SQL file in the specified location (default: D:/xamp/htdocs/tableEditor/test.sql)

Set proper permissions:

bash
chmod -R 755 storage/
chmod -R 755 uploads/
Usage
Home Page
Execute SQL files to initialize the database

View success/error messages

Tables Section
New Records:

Export CSV templates

Import CSV files to create multiple records

Update Records:

Export existing data

Import CSV files for updates

Build complex update queries with multiple conditions

Navigation
Use the sidebar to switch between home and tables sections

Table dropdown shows available tables with search functionality


Configuration Options
config.php Settings
DB_*: Database connection parameters

BASE_DIR: Base directory for file operations

SQL_FILE: Path to your SQL initialization file

$tableAliases: Array mapping table names to display names

$columnAliases: Nested array mapping table.column to display names

$visibleTables: Array of tables to show in the interface

Security Considerations
Database Credentials:

Never commit sensitive credentials to version control

Consider using environment variables for production

File Uploads:

The application validates file types (CSV only)

Implement additional server-side validation for production use

SQL Injection:

The application uses prepared statements for database operations

Still, be cautious with direct SQL execution

Access Control:

Implement authentication for production use

Restrict access to authorized users only

Troubleshooting
Common Issues
SQL File Not Executing:

Verify file path in SQL_FILE constant

Check file permissions

Ensure SQL syntax is valid

Database Connection Errors:

Verify credentials in config.php

Check if MySQL server is running

Ensure the database exists

Import/Export Issues:

Verify CSV file format

Check column names match database structure

Ensure proper file permissions


Contributing
Fork the repository

Create your feature branch (git checkout -b feature/fooBar)

Commit your changes (git commit -am 'Add some fooBar')

Push to the branch (git push origin feature/fooBar)

Create a new Pull Request

Roadmap
User authentication system

Advanced query builder

Data visualization tools

Multi-database support

Scheduled backups

text

### Recommended File Structure
db-wizard/
├── config.php # Configuration file
├── index.php # Main application file
├── style.css # CSS styles
├── test.sql # Sample SQL file
├── README.md # This documentation
├── screenshots/ # Folder for screenshots
│ ├── home.png
│ ├── tables.png
│ └── update.png
└── assets/ # Optional assets folder
├── js/
└── images/
