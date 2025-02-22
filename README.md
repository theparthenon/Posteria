# Posteria

Posteria is a web-based media poster management system that allows you to organize and store custom posters for your movies, TV shows, seasons, and collections. It provides an elegant interface for uploading, managing, and accessing your media artwork.

## Features

- 🖼️ Clean, modern interface for managing media posters
- 📁 Organized categories for Movies, TV Shows, TV Seasons, and Collections
- 🔍 Fast, fuzzy search functionality
- 📱 Mobile-responsive design
- 🔒 Simple authentication system
- ⚡ Easy poster upload from local files or URLs
- 🔄 Move posters between categories
- 🎨 Support for JPG, JPEG, PNG, and WebP formats

### If you find this tool useful, consider helping me buy more hard drives!

[![](https://jereme.dev/images/paypal-donate-button.png)](https://www.paypal.com/ncp/payment/7WSTDKQ4PCNXQ)

## Screenshot
![Posteria](https://raw.githubusercontent.com/jeremehancock/Posteria/main/images/screenshot.png "Posteria")

## Installation

1. Create a `docker-compose.yml` file with the following content:

```yaml
services:
  posteria:
    image: bozodev/posteria:latest
    container_name: posteria
    ports:
      - "8181:80"
    environment:
      - SITE_TITLE=Posteria
      - AUTH_USERNAME=admin          # Change this!
      - AUTH_PASSWORD=changeme       # Change this!
      - SESSION_DURATION=3600        # In seconds
      - IMAGES_PER_PAGE=24
      - MAX_FILE_SIZE=5242880        # In bytes
    volumes:
      - ./posters/movies:/var/www/html/posters/movies
      - ./posters/tv-shows:/var/www/html/posters/tv-shows
      - ./posters/tv-seasons:/var/www/html/posters/tv-seasons
      - ./posters/collections:/var/www/html/posters/collections
    restart: unless-stopped
```

2. Start the container:
```bash
docker-compose up -d
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| SITE_TITLE | Website title | Posteria |
| AUTH_USERNAME | Admin username | admin |
| AUTH_PASSWORD | Admin password | changeme |
| SESSION_DURATION | Login session duration in seconds | 3600 (1 Hour) |
| IMAGES_PER_PAGE | Number of posters displayed per page | 24 |
| MAX_FILE_SIZE | Maximum upload file size in bytes | 5242880 (5MB) |

### Volume Mounts

The Docker container uses the following volume mounts:

- `./posters/movies`: Movie posters
- `./posters/tv-shows`: TV show posters
- `./posters/tv-seasons`: TV season posters
- `./posters/collections`: Collection posters

## Usage

1. Access the web interface at `http://your-server:8181`
2. Log in using your configured credentials
3. Upload posters via the upload button:
   - Support for local file upload
   - Support for direct URL upload
4. Manage your posters:
   - Move between categories
   - Rename files
   - Delete unwanted posters
   - Copy direct URLs for use in other applications

## Security Considerations

1. Change the default username and password
2. Use HTTPS if exposing to the internet
3. Regularly backup your poster directories

## License

[MIT License](LICENSE)

## AI Assistance Disclosure

This tool was developed with assistance from AI language models.