# Library Staff Images Feature Documentation

## Overview
This document describes the implementation of a feature that manages library staff images. The feature ensures that each library staff member (bty 40) has an image locally, sends warning emails for missing images, and keeps local and remote image directories in sync.

## Implementation Details

### Script
- **MNPS_librarian_facebook_image_sync.sh**: A bash script that:
  - Identifies library staff with borrower type 40
  - Checks if each library staff has an image
  - Sends warning emails for missing images
  - Creates a temporary directory containing only bty=40 images
  - Syncs only these specific images with a remote server (local is master)
  - Cleans up the temporary directory after syncing

## Usage
To use the library staff images feature, run the MNPS_librarian_facebook_image_sync.sh script from the command line:

```bash
./MNPS_librarian_facebook_image_sync.sh [remote_user] [image_dir] [remote_image_dir]
```

### Parameters
- `remote_user` - Remote user and server (default: user@remote.server)
- `image_dir` - Local directory containing staff images (default: ../data/images/Staff)
- `remote_image_dir` - Remote directory for staff images (default: mnpslibrarianfacebook_images)

## Configuration
The feature uses the following configuration:

- Email recipients are read from the `librarianFacebookEmailRecipients` setting in `../config.pwd.ini`
- CSV file path: `../data/ic2carlx_mnps_staff_carlx.csv`
- Log file path: `../data/MNPS_librarian_facebook_image_sync.log`

## Authentication
The script supports two authentication methods for syncing with the remote server:
1. SSH key authentication (attempted first)
2. Password authentication (fallback)
   - Requires the `sshpass` utility to be installed
   - Password is entered interactively for better security

## Logging
The feature logs all activities to the log file. The log includes:
- Start and end times
- Number of library staff found
- Missing images
- Sync status
- Authentication method used
- Cleanup operations

## Email Notifications
Warning emails are sent in the following cases:
- No library staff (bty 40) found in the CSV file
- One or more library staff members are missing images
- Sync with remote server fails
- Required utilities (like sshpass) are missing when needed

## Maintenance
To maintain this feature:
1. Ensure the CSV file format remains consistent (ID in first column, BORROWERTYPEID in second column)
2. Update email recipients in the config.pwd.ini file as needed
3. Check log files regularly for any issues
4. Verify that the remote server is accessible and has sufficient storage space
5. Ensure SSH keys are properly set up or sshpass is installed for password authentication