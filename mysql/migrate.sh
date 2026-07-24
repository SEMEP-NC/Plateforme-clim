#!/bin/bash
set -e
echo "=== Database migration ==="
MYSQL="mysql -u${MYSQL_USER} -p${MYSQL_PASSWORD} ${MYSQL_DATABASE}"
echo "Checking migration table..."
$MYSQL <<EOF
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
EOF
for file in /migrations/*.sql
do
    filename=$(basename "$file")
    applied=$(
        $MYSQL -N -s -e \
        "SELECT COUNT(*) FROM schema_migrations WHERE filename='$filename';"
    )
    if [ "$applied" = "0" ]
    then
        echo "Applying $filename"
        $MYSQL < "$file"
        $MYSQL -e \
        "INSERT INTO schema_migrations(filename)
         VALUES('$filename');"
    else
        echo "Skipping $filename"
    fi
done
echo "=== Migration completed ==="