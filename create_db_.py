import pymysql
from pymysql.err import OperationalError, Error as PyMySQLError
import logging
from logging.handlers import RotatingFileHandler
import os
from config import DB_HOST, DB_USER, DB_PASS, DB_NAME, TABLE_NAME, TABLE_NAME2

def setup_logging():
    """Configure logging for database creation"""
    logger = logging.getLogger('database_setup')
    logger.setLevel(logging.INFO)
    
    # Create logs directory if it doesn't exist
    if not os.path.exists('logs'):
        os.makedirs('logs')
    
    # File handler with rotation
    file_handler = RotatingFileHandler(
        'logs/database_setup.log',
        maxBytes=5*1024*1024,  # 5MB
        backupCount=3
    )
    file_handler.setLevel(logging.INFO)
    
    # Console handler
    console_handler = logging.StreamHandler()
    console_handler.setLevel(logging.INFO)
    
    # Format for logs
    formatter = logging.Formatter(
        '%(asctime)s - %(levelname)s - %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S'
    )
    file_handler.setFormatter(formatter)
    console_handler.setFormatter(formatter)
    
    logger.addHandler(file_handler)
    logger.addHandler(console_handler)
    
    return logger

def connect_db(database=None, max_retries=3, retry_delay=5):
    """Connect to MySQL/MariaDB with retry mechanism"""
    logger = logging.getLogger('database_setup')
    retry_count = 0
    
    while retry_count < max_retries:
        try:
            conn = pymysql.connect(
                host=DB_HOST,
                user=DB_USER,
                password=DB_PASS,
                database=database,
                charset='utf8mb4',
                cursorclass=pymysql.cursors.DictCursor,
                autocommit=True,
                connect_timeout=10
            )
            logger.info("✅ Successfully connected to database server")
            return conn
        except OperationalError as e:
            retry_count += 1
            logger.warning(f"❌ Database connection attempt {retry_count} failed: {e}")
            if retry_count < max_retries:
                logger.info(f"⏳ Retrying in {retry_delay} seconds...")
                import time
                time.sleep(retry_delay)
    
    logger.error("❌ Maximum database connection retries reached")
    return None

def create_database_and_table():
    """Create database and tables with proper error handling"""
    logger = setup_logging()
    conn = None
    
    try:
        # Connect without selecting a database first
        logger.info("🔄 Connecting to database server...")
        conn = connect_db()
        if not conn:
            logger.error("❌ Could not connect to database server")
            return False

        try:
            with conn.cursor() as cursor:
                # Create database with proper collation
                logger.info(f"🔄 Creating database {DB_NAME} if it doesn't exist...")
                cursor.execute(
                    f"CREATE DATABASE IF NOT EXISTS {DB_NAME} "
                    "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
                )
                
                # Switch to the database
                cursor.execute(f"USE {DB_NAME};")

                # Create the users table used by the dashboard
                logger.info("🔄 Creating table users if it doesn't exist...")
                cursor.execute("""
                    CREATE TABLE IF NOT EXISTS users (
                        id INT NOT NULL PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        title VARCHAR(255) DEFAULT NULL,
                        department VARCHAR(255) DEFAULT NULL,
                        created_at TIMESTAMP NULL,
                        updated_at TIMESTAMP NULL,
                        active TINYINT(1) NOT NULL DEFAULT 1,
                        view_order INT DEFAULT NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                """)

                # Create the settings table used by attendance filters
                logger.info("🔄 Creating table settings if it doesn't exist...")
                cursor.execute("""
                    CREATE TABLE IF NOT EXISTS settings (
                        `key` VARCHAR(255) NOT NULL PRIMARY KEY,
                        `value` TEXT DEFAULT NULL,
                        created_at TIMESTAMP NULL,
                        updated_at TIMESTAMP NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                """)
                
                # Create table with proper indexes and constraints
                logger.info(f"🔄 Creating table {TABLE_NAME} if it doesn't exist...")
                cursor.execute(f"""
                    CREATE TABLE IF NOT EXISTS {TABLE_NAME} (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        user_id VARCHAR(50) NOT NULL,
                        timestamp DATETIME NOT NULL,
                        status VARCHAR(50) DEFAULT NULL,
                        punch VARCHAR(50) DEFAULT NULL,
                        message VARCHAR(255) DEFAULT NULL,
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL,
                        UNIQUE KEY uq_user_time (user_id, timestamp),
                        INDEX idx_timestamp (timestamp),
                        INDEX idx_user_id (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                """)
                
                # Add any additional indexes if they don't exist
                logger.info("🔄 Checking and adding additional indexes...")
                cursor.execute(f"""
                    SELECT COUNT(*) as count FROM information_schema.statistics 
                    WHERE table_schema = '{DB_NAME}' 
                    AND table_name = '{TABLE_NAME}' 
                    AND index_name = 'idx_created_at';
                """)
                if cursor.fetchone()['count'] == 0:
                    cursor.execute(f"""
                        ALTER TABLE {TABLE_NAME} 
                        ADD INDEX idx_created_at (created_at);
                    """)

                # Create the second device attendance table used by the dashboard
                logger.info(f"🔄 Creating table {TABLE_NAME2} if it doesn't exist...")
                cursor.execute(f"""
                    CREATE TABLE IF NOT EXISTS {TABLE_NAME2} (
                        id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        user_id VARCHAR(50) NOT NULL,
                        timestamp DATETIME NOT NULL,
                        status VARCHAR(50) DEFAULT NULL,
                        punch VARCHAR(50) DEFAULT NULL,
                        message VARCHAR(255) DEFAULT NULL,
                        created_at DATETIME NOT NULL,
                        updated_at DATETIME NOT NULL,
                        UNIQUE KEY uq_user_time (user_id, timestamp),
                        INDEX idx_timestamp (timestamp),
                        INDEX idx_user_id (user_id),
                        INDEX idx_created_at (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                """)

                logger.info("✅ Database and table setup completed successfully")
                return True

        except PyMySQLError as e:
            logger.error(f"❌ Error creating database or table: {e}")
            return False

    except Exception as e:
        logger.error(f"❌ Unexpected error: {e}")
        return False

    finally:
        if conn:
            conn.close()
            logger.info("🔄 Database connection closed")

def verify_database_setup():
    """Verify the database setup and configuration"""
    logger = logging.getLogger('database_setup')
    conn = None
    
    try:
        logger.info("🔄 Verifying database setup...")
        conn = connect_db(database=DB_NAME)
        if not conn:
            return False

        with conn.cursor() as cursor:
            # Check if we can actually write to the table
            for table_name in (TABLE_NAME, TABLE_NAME2):
                cursor.execute(f"""
                    INSERT INTO {table_name}
                    (user_id, timestamp, status, created_at, updated_at)
                    VALUES ('TEST', NOW(), 'TEST', NOW(), NOW())
                """)

                cursor.execute(f"""
                    DELETE FROM {table_name}
                    WHERE user_id = 'TEST' AND status = 'TEST'
                """)
            
            logger.info("✅ Database verification completed successfully")
            return True

    except Exception as e:
        logger.error(f"❌ Database verification failed: {e}")
        return False

    finally:
        if conn:
            conn.close()

if __name__ == "__main__":
    if create_database_and_table():
        verify_database_setup()