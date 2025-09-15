#!/bin/bash
# MNPS_librarian_facebook_image_sync.sh
# Created: 2025-09-15
# Nashville Public Library
#
# This script manages library staff images (bty 40):
# 1. Checks if each library staff (bty 40) has an image
# 2. Sends warning emails for missing images
# 3. Syncs ONLY bty=40 library staff images with remote server (local is master)
#    - Creates a temporary directory containing only bty=40 images
#    - Syncs only these specific images, not the entire Staff directory
#    - Cleans up the temporary directory after syncing
#
# Usage:
#   ./MNPS_librarian_facebook_image_sync.sh [remote_user] [image_dir] [remote_image_dir]
#
# Parameters:
#   remote_user      - Remote user and server (default: user@remote.server)
#   image_dir        - Local directory containing staff images (default: ../data/images/Staff)
#   remote_image_dir - Remote directory for staff images (default: mnpslibrarianfacebook_images)
#
# Notes:
#   - If SSH key authentication is not set up, you will be prompted for a password
#   - The password is entered interactively for better security
#   - The script will first try to use SSH key authentication
#   - If key authentication fails, it will prompt for a password
#   - If using password authentication, the sshpass utility must be installed

# Configuration
remote_user="${1:-user@remote.server}"
image_dir="${2:-../data/images/Staff}"
remote_image_dir="${3:-mnpslibrarianfacebook_images}"
# SSH password will be prompted if needed
csv_file="../data/ic2carlx_mnps_staff_carlx.csv"
email_recipients=$(awk -F "=" '/librarianFacebookEmailRecipients/ {print $2}' ../config.pwd.ini | tr -d ' ' | sed 's/^"\(.*\)"$/\1/')
log_file="../data/MNPS_librarian_facebook_image_sync.log"

# Initialize log file
echo "$(date): Starting library staff image management" > "$log_file"

# Extract library staff (bty 40) from CSV file
# Format of CSV: ID,BORROWERTYPEID,...
echo "$(date): Extracting library staff (bty 40) from CSV" >> "$log_file"
mapfile -t library_staff < <(awk -F, '$2 == "40" {print $1}' "$csv_file")

# Check if we found any library staff
if [ ${#library_staff[@]} -eq 0 ]; then
    echo "$(date): No library staff (bty 40) found in CSV file" >> "$log_file"
    echo "No library staff (bty 40) found in CSV file" | mail -s "Library Staff Images: No staff found" $email_recipients
    exit 1
fi

echo "$(date): Found ${#library_staff[@]} library staff members" >> "$log_file"

# Check for missing images
missing_images=()
for staff_id in "${library_staff[@]}"; do
    image_path="${image_dir}/${staff_id}.jpg"
    if [ ! -f "$image_path" ]; then
        missing_images+=("$staff_id")
        echo "$(date): Missing image for library staff $staff_id" >> "$log_file"
    fi
done

# Send warning email if there are missing images
if [ ${#missing_images[@]} -gt 0 ]; then
    echo "$(date): Sending warning email for ${#missing_images[@]} missing images" >> "$log_file"
    {
        echo "The following library staff members (bty 40) are missing images:"
        echo ""
        for staff_id in "${missing_images[@]}"; do
            echo "Staff ID: $staff_id"
        done
        echo ""
        echo "Please ensure all library staff have images uploaded to the system."
    } | mail -s "Library Staff Images: Missing Images Warning" $email_recipients
fi

# Create temporary directory for bty=40 images only
temp_dir=$(mktemp -d)
echo "$(date): Creating temporary directory for bty=40 images only" >> "$log_file"

# Copy only bty=40 images to temporary directory
for staff_id in "${library_staff[@]}"; do
    image_path="${image_dir}/${staff_id}.jpg"
    if [ -f "$image_path" ]; then
        cp "$image_path" "${temp_dir}/"
        echo "$(date): Copied image for staff_id $staff_id to temporary directory" >> "$log_file"
    fi
done

# Sync only bty=40 images to remote server (local is master)
echo "$(date): Syncing only bty=40 library staff images to remote server" >> "$log_file"

# First try with SSH key authentication
echo "$(date): Attempting to sync with SSH key authentication" >> "$log_file"
rsync_output=$(rsync -av --delete "${temp_dir}/" "${remote_user}:${remote_image_dir}" 2>&1)
rsync_status=$?

# If SSH key authentication fails, try with password
if [ $rsync_status -ne 0 ] && [[ "$rsync_output" == *"Permission denied"* ]]; then
    echo "$(date): SSH key authentication failed, trying password authentication" >> "$log_file"
    
    # Check if sshpass is installed
    if command -v sshpass >/dev/null 2>&1; then
        echo "$(date): Using password authentication for rsync" >> "$log_file"
        
        # Prompt for password (will not be echoed to terminal)
        echo "Enter SSH password for ${remote_user}:"
        read -s ssh_password
        
        # Use the entered password with sshpass
        rsync_output=$(SSHPASS="$ssh_password" sshpass -e rsync -av --delete "${temp_dir}/" "${remote_user}:${remote_image_dir}" 2>&1)
        rsync_status=$?
        
        # Clear the password variable for security
        ssh_password=""
    else
        echo "$(date): Error: sshpass is not installed but password authentication is needed" >> "$log_file"
        echo "Error: sshpass is not installed but password authentication is needed. Please install sshpass or set up SSH keys." | mail -s "Library Staff Images: Sync Failed - Missing sshpass" $email_recipients
        exit 1
    fi
fi

if [ $rsync_status -eq 0 ]; then
    echo "$(date): Successfully synced library staff images to remote server" >> "$log_file"
else
    echo "$(date): Failed to sync library staff images to remote server" >> "$log_file"
    echo "$(date): rsync error: $rsync_output" >> "$log_file"
    echo "Failed to sync library staff images to remote server. Error: $rsync_output" | mail -s "Library Staff Images: Sync Failed" $email_recipients
fi

# Clean up temporary directory
if [ -d "$temp_dir" ]; then
    echo "$(date): Cleaning up temporary directory" >> "$log_file"
    rm -rf "$temp_dir"
fi

echo "$(date): Library staff image management completed" >> "$log_file"
exit 0