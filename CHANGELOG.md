# Changelog

All notable changes to this project are documented in this file. The format is
loosely based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Fixed

- Login lockout: a retry after a failed login returned a 500 on cache stores that
  serialize values (Redis, the `.env.example` default), because the lockout deadline
  was cached as a Carbon object that `serializable_classes => false` refuses to
  restore. The deadline is now stored as a Unix timestamp.
  A lockout value left in the cache by the old code is now ignored instead of failing
  the login for the hour it can linger.
