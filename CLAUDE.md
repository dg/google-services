# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A PHP library that provides a simplified interface for Google Calendar API operations, focusing on OAuth2 authentication and calendar event management. The library wraps the official Google API client with a more ergonomic API.

## Architecture

### Core Components

**Authenticator (`src/Authenticator.php`)**
- Manages OAuth2 authentication flow with Google APIs
- Handles token storage and refresh in `demo/tokens/` directory
- Requires `secret.json` (OAuth2 credentials from Google Cloud Console)
- Stores `token.json` (access and refresh tokens)
- Automatically refreshes expired tokens using refresh token
- Throws `RuntimeException` when new authorization is required

**CalendarManager (`src/CalendarManager.php`)**
- Main API for calendar operations (create events, manage attendees)
- Works with either primary calendar or specified calendar ID
- Supports creating Google Meet meetings automatically
- Handles recurring events with daily frequency and intervals
- Email validation and normalization for attendees

**CalendarEvent (`src/CalendarEvent.php`)**
- Data transfer object for event creation
- Supports optional location, description, reminders
- Recurring events via `repeatCount` and `repeatIntervalDays`
- Optional Google Meet creation via `createMeeting` flag

### OAuth2 Flow

1. User runs `demo/authenticate.php` → redirects to Google authorization
2. Google redirects to `demo/oauth2callback.php` with authorization code
3. Callback exchanges code for access token and saves to `demo/tokens/token.json`
4. Subsequent requests use stored token, auto-refreshing when expired

### Demo Structure

The `demo/` directory contains working examples:
- `bootstrap.php` - Shared setup with `createAuthenticator()` and `googleAuthenticate()` helpers
- `authenticate.php` - Initiates OAuth2 flow
- `oauth2callback.php` - Handles OAuth2 callback and token exchange
- `list-calendars.php` - Example of listing calendars using authenticated client
- `tokens/` - Directory for OAuth2 credentials and tokens (gitignored)

## Setup Requirements

1. Create OAuth 2.0 Client in [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
2. Add authorized redirect URI pointing to `oauth2callback.php`
3. Download OAuth2 credentials and save as `demo/tokens/secret.json`
4. Run `demo/authenticate.php` to obtain access token

## Dependencies

- PHP 8.2 - 8.5
- `google/apiclient` ^2.18 - Official Google API client library
- Autoloading via classmap of `src/` directory

## Common Tasks

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Run demo authentication flow
php demo/authenticate.php

# List calendars after authentication
php demo/list-calendars.php
```

## Code Style

- All PHP files use `declare(strict_types=1)`
- Namespace: `DG\Google`
- Two empty lines between methods
- Constructor property promotion used throughout
- Exception handling: Catch Google API exceptions, throw `RuntimeException` with context
