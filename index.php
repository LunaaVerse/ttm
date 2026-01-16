<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic and Transport</title>
    <link rel="icon" type="image/png" href="img/frsm-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- MapTiler -->
    <link href="https://api.maptiler.com/maps/satellite/style.json?key=gZtMDh9pV46hFgly6xCT" rel="stylesheet" />
    <script src="https://api.maptiler.com/maps/satellite/?key=gZtMDh9pV46hFgly6xCT"></script>
    <script src="https://unpkg.com/maplibre-gl@2.4.0/dist/maplibre-gl.js"></script>
    <link href="https://unpkg.com/maplibre-gl@2.4.0/dist/maplibre-gl.css" rel="stylesheet" />
    <style>
        :root {
            /* Traffic & Transport Management Color Palette */
            --primary-color: #0d9488;
            --primary-dark: #0f766e;
            --secondary-color: #047857;
            --secondary-dark: #065f46;
            --background-color: #f3f4f6;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            
            /* Additional colors for accents */
            --accent: #10b981;
            --white: #ffffff;
            --dark-gray: #212121;
            --light-gray: #f5f5f5;
            
            /* Gradients */
            --gradient: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 50%, var(--secondary-dark) 100%);
            --gradient-dark: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%);
            
            /* Shadows and effects */
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            --hover-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            --glow: 0 0 15px rgba(13, 148, 136, 0.5);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-color);
            background-color: var(--background-color);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        h1, h2, h3, h4, h5 {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            color: var(--primary-dark);
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Particle Background */
        .particles-container {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
            overflow: hidden;
        }
        
        .particle {
            position: absolute;
            background-color: rgba(13, 148, 136, 0.3);
            border-radius: 50%;
            animation: float 15s infinite linear;
        }
        
        @keyframes float {
            0% {
                transform: translateY(0) translateX(0) rotate(0deg);
                opacity: 0.3;
            }
            50% {
                opacity: 0.6;
            }
            100% {
                transform: translateY(-100vh) translateX(100px) rotate(360deg);
                opacity: 0;
            }
        }
        
        /* Header */
        header {
            background: var(--gradient);
            color: white;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            min-height: 48px;
        }

        header.scrolled {
            padding: 0.8rem 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            background: var(--gradient-dark);
            min-height: 40px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logo-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3px;
            box-shadow: var(--glow);
            transition: transform 0.3s, box-shadow 0.3s;
            background: white;
        }

        .logo-img:hover {
            transform: rotate(15deg) scale(1.08);
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
        }

        .logo-img img {
            width: 100%;
            height: auto;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.3);
            position: relative;
        }

        .logo-text:after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: white;
            transition: width 0.3s ease;
        }

        .logo:hover .logo-text:after {
            width: 100%;
        }

        nav ul {
            display: flex;
            list-style: none;
            gap: 0.7rem;
            align-items: center;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s;
            position: relative;
            padding: 0.3rem 0;
        }

        nav a:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 0;
            background-color: white;
            transition: width 0.3s;
        }

        nav a:hover:after {
            width: 100%;
        }

        .auth-buttons {
            display: flex;
            gap: 0.7rem;
            margin-left: 0.7rem;
        }

        .auth-btn {
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 0.85rem;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }

        .auth-btn:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0%;
            height: 100%;
            background: white;
            transition: all 0.3s;
            z-index: -1;
        }

        .login-btn {
            background-color: transparent;
            color: white;
            border: 2px solid white;
        }

        .login-btn:hover:before {
            width: 100%;
        }

        .login-btn:hover {
            color: var(--primary-color);
        }

        .register-btn {
            background-color: white;
            color: var(--primary-color);
            border: 2px solid white;
        }

        .register-btn:before {
            background: var(--primary-dark);
        }

        .register-btn:hover:before {
            width: 100%;
        }

        .register-btn:hover {
            color: white;
            border-color: var(--primary-dark);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            z-index: 1000;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        /* Language Selector */
        .language-selector {
            position: relative;
            margin-left: 0.7rem;
        }

        .language-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            border-radius: 20px;
            padding: 0.3rem 0.8rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .language-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .language-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: var(--card-shadow);
            padding: 0.5rem 0;
            margin-top: 0.5rem;
            min-width: 120px;
            display: none;
            z-index: 1000;
        }

        .language-dropdown.active {
            display: block;
        }

        .language-option {
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--text-color);
            text-decoration: none;
            display: block;
            font-size: 0.95rem;
        }

        .language-option:hover {
            background: var(--background-color);
            color: var(--primary-color);
        }
        
        /* Hero Section */
        .hero {
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" viewBox="0 0 800 400"><rect fill="%230d9488" width="800" height="400"/><path fill="%230f766e" fill-opacity="0.4" d="M0 192L26.7 202.7C53.3 213 107 235 160 234.7C213.3 235 267 213 320 197.3C373.3 181 427 171 480 186.7C533.3 203 587 245 640 250.7C693.3 256 747 224 773.3 208L800 192L800 401L773.3 401C746.7 401 693 401 640 401C586.7 401 533 401 480 401C426.7 401 373 401 320 401C266.7 401 213 401 160 401C106.7 401 53 401 27 401L0 401Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            color: white;
            padding: 5rem 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
        }
        
        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        .hero h1 {
            font-size: 3.2rem;
            margin-bottom: 1.5rem;
            color: white;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 1s ease-out;
        }
        
        .hero p {
            font-size: 1.3rem;
            margin-bottom: 2.5rem;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            animation: fadeInUp 1.2s ease-out;
        }
        
        .btn {
            display: inline-block;
            background-color: white;
            color: var(--primary-color);
            padding: 1rem 2rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            animation: fadeInUp 1.4s ease-out;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn:before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: 0.5s;
            z-index: -1;
        }
        
        .btn:hover:before {
            left: 100%;
        }
        
        .btn:hover {
            background-color: transparent;
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
        }
        
        /* Widget Section */
        .widget-section {
            padding: 3rem 0;
            background-color: var(--background-color);
        }
        
        .widget-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .widget-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 2rem;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }
        
        .widget-card:hover {
            transform: translateY(-5px);
        }
        
        .widget-card h3 {
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
        }
        
        .widget-card h3:after {
            content: '';
            position: absolute;
            width: 50px;
            height: 3px;
            bottom: -8px;
            left: 0;
            background: var(--primary-color);
        }
        
        .location-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .location-form input {
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        .location-form button {
            background: var(--gradient);
            color: white;
            border: none;
            padding: 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .location-form button:hover {
            background: var(--gradient-dark);
        }
        
        .nearest-station {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: var(--background-color);
            border-radius: 8px;
            display: none;
        }
        
        .nearest-station.active {
            display: block;
        }
        
        .nearest-station h4 {
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        /* Services Section */
        .services {
            padding: 5rem 0;
            background-color: var(--card-bg);
            position: relative;
        }
        
        .services:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100" opacity="0.03"><path d="M0 0 L100 100 M100 0 L0 100" stroke="%230d9488" stroke-width="2"/></svg>');
            background-size: 200px;
            pointer-events: none;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 3.5rem;
            position: relative;
        }
        
        .section-title:after {
            content: '';
            position: absolute;
            width: 80px;
            height: 4px;
            background-color: var(--primary-color);
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 2px;
        }
        
        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2.5rem;
        }
        
        .service-card {
            background: var(--background-color);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            box-shadow: var(--card-shadow);
            position: relative;
            top: 0;
            border: 1px solid rgba(13, 148, 136, 0.1);
        }
        
        .service-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--hover-shadow);
        }
        
        .service-icon {
            background: var(--gradient);
            color: white;
            font-size: 2.2rem;
            padding: 2rem;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        .service-icon::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 100%;
            background: rgba(255, 255, 255, 0.2);
            transform: skewX(-20deg) translateX(-200%);
            transition: transform 0.6s;
        }
        
        .service-card:hover .service-icon::after {
            transform: skewX(-20deg) translateX(500%);
        }
        
        .service-content {
            padding: 2rem;
        }
        
        .service-content h3 {
            margin-bottom: 1.2rem;
            font-size: 1.4rem;
            position: relative;
            display: inline-block;
        }
        
        .service-content h3:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 0;
            background: var(--primary-color);
            transition: width 0.3s;
        }
        
        .service-card:hover .service-content h3:after {
            width: 100%;
        }
        
        .service-content p {
            color: var(--text-light);
        }
        
        /* About Us Section */
        .about {
            padding: 5rem 0;
            background-color: var(--background-color);
            position: relative;
        }
        
        .about:before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 300px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><polygon points="50,0 100,50 50,100 0,50" fill="%230d9488" opacity="0.05"/></svg>');
            background-size: contain;
            background-repeat: no-repeat;
            pointer-events: none;
        }
        
        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }
        
        .about-text h2 {
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
        }
        
        .about-text h2:after {
            content: '';
            position: absolute;
            width: 100%;
            height: 3px;
            bottom: -10px;
            left: 0;
            background: var(--gradient);
            border-radius: 3px;
        }
        
        .about-text p {
            margin-bottom: 1.5rem;
            line-height: 1.8;
        }
        
        .about-image {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--hover-shadow);
            transition: transform 0.5s;
            position: relative;
            border: 5px solid white;
        }
        
        .about-image:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            opacity: 0;
            z-index: 1;
            transition: opacity 0.3s;
            border-radius: 8px;
        }
        
        .about-image:hover:before {
            opacity: 0.1;
        }
        
        .about-image:hover {
            transform: scale(1.02);
        }
        
        .about-image img {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.5s;
        }
        
        .about-image:hover img {
            transform: scale(1.05);
        }
        
        /* Leadership Section */
        .leadership {
            padding: 5rem 0;
            background-color: var(--card-bg);
            position: relative;
        }
        
        .leadership:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--primary-color), transparent);
        }
        
        .leadership-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2.5rem;
        }
        
        .leader-card {
            background: var(--background-color);
            border-radius: 12px;
            overflow: hidden;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
            position: relative;
            border: 1px solid rgba(13, 148, 136, 0.1);
        }
        
        .leader-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--hover-shadow);
        }
        
        .leader-image {
            width: 100%;
            height: 250px;
            background-color: #f0f0f0;
            position: relative;
            overflow: hidden;
        }
        
        .leader-image:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient);
            opacity: 0;
            z-index: 1;
            transition: opacity 0.3s;
            border-radius: 8px;
        }
        
        .leader-card:hover .leader-image:before {
            opacity: 0.2;
        }
        
        .leader-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }
        
        .leader-card:hover .leader-image img {
            transform: scale(1.1);
        }
        
        .leader-info {
            padding: 1.5rem;
        }
        
        .leader-info h3 {
            margin-bottom: 0.5rem;
            position: relative;
            display: inline-block;
        }
        
        .leader-info h3:after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 0;
            background: var(--primary-color);
            transition: width 0.3s;
            border-radius: 2px;
        }
        
        .leader-card:hover .leader-info h3:after {
            width: 100%;
        }
        
        .leader-role {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .leader-info p {
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        /* Stats Section */
        .stats {
            padding: 5rem 0;
            background: var(--gradient);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .stats::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100" opacity="0.05"><circle cx="50" cy="50" r="40" fill="white" /></svg>');
            background-size: 200px;
            opacity: 0.1;
        }
        
        .stats h2 {
            color: white;
            text-align: center;
            margin-bottom: 3.5rem;
            position: relative;
            z-index: 1;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2.5rem;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .stat-item {
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            backdrop-filter: blur(5px);
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .stat-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
            z-index: -1;
        }
        
        .stat-item:hover {
            transform: scale(1.05);
            background: rgba(255, 255, 255, 0.15);
        }
        
        .stat-item h3 {
            font-size: 2.8rem;
            margin-bottom: 0.8rem;
            color: white;
            text-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        }
        
        .stat-item p {
            font-size: 1.1rem;
        }
        
        /* Chart Section */
        .chart-section {
            padding: 5rem 0;
            background-color: var(--background-color);
            position: relative;
        }
        
        .chart-section:before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--primary-color), transparent);
        }
        
        .chart-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2.5rem;
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(13, 148, 136, 0.1);
        }
        
        .chart-container:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient);
        }
        
        /* Map Section */
        .map-section {
            padding: 80px 0;
            background: var(--card-bg);
        }
        
        .map-container {
            width: 100%;
            height: 500px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 40px;
            position: relative;
        }
        
        #map {
            width: 100%;
            height: 100%;
        }
        
        .map-controls {
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
            display: flex;
            gap: 10px;
        }
        
        .map-btn {
            background: var(--card-bg);
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            font-weight: 500;
            box-shadow: var(--card-shadow);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color);
        }
        
        .map-btn:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .maplibregl-popup-content {
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 15px;
            font-family: 'Inter', sans-serif;
        }
        
        .maplibregl-popup-content h4 {
            color: var(--primary-color);
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .maplibregl-popup-content p {
            margin: 0;
            font-size: 14px;
            color: var(--text-color);
        }
        
        /* Map Fullscreen */
        .map-fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1000;
            border-radius: 0;
        }
        
        /* Fire Station Table Section */
        .station-section {
            padding: 80px 0;
            background: var(--background-color);
        }
        
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .search-filter {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input, .district-filter {
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        .search-input:focus, .district-filter:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .station-table {
            background: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            margin-bottom: 30px;
        }
        
        .station-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .station-table th {
            background: var(--gradient);
            color: white;
            padding: 20px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .station-table td {
            padding: 18px 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .station-table tbody tr:hover {
            background: var(--background-color);
            transition: background 0.3s ease;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active {
            background: #e8f5e8;
            color: #2e7d32;
        }
        
        .status-maintenance {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagination button {
            padding: 10px 15px;
            border: 2px solid var(--primary-color);
            background: var(--card-bg);
            color: var(--primary-color);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination button:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination span {
            font-size: 14px;
            color: var(--text-color);
        }
        
        /* FAQ Section */
        .faq-section {
            padding: 5rem 0;
            background-color: var(--card-bg);
        }
        
        .faq-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .faq-item {
            margin-bottom: 1.5rem;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: all 0.3s;
        }
        
        .faq-item:hover {
            box-shadow: var(--hover-shadow);
        }
        
        .faq-question {
            background: var(--background-color);
            padding: 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .faq-question:hover {
            background: #e9e9e9;
        }
        
        .faq-question i {
            transition: transform 0.3s;
        }
        
        .faq-item.active .faq-question i {
            transform: rotate(180deg);
        }
        
        .faq-answer {
            padding: 0 1.5rem;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .faq-item.active .faq-answer {
            padding: 1.5rem;
            max-height: 500px;
        }
        
        /* Educational Materials */
        .materials-section {
            padding: 5rem 0;
            background-color: var(--background-color);
        }
        
        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .material-card {
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }
        
        .material-card:hover {
            transform: translateY(-5px);
        }
        
        .material-icon {
            background: var(--gradient);
            color: white;
            font-size: 2rem;
            padding: 2rem;
            text-align: center;
        }
        
        .material-content {
            padding: 1.5rem;
        }
        
        .material-content h3 {
            margin-bottom: 1rem;
        }
        
        .download-btn {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        
        .download-btn:hover {
            background: var(--primary-dark);
        }
        
        /* News Section */
        .news-section {
            padding: 5rem 0;
            background-color: var(--card-bg);
        }
        
        .news-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .news-card {
            background: var(--background-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s;
        }
        
        .news-card:hover {
            transform: translateY(-5px);
        }
        
        .news-content {
            padding: 1.5rem;
        }
        
        .news-date {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .news-content h3 {
            margin-bottom: 1rem;
        }
        
        .read-more {
            display: inline-block;
            color: var(--primary-color);
            font-weight: 600;
            text-decoration: none;
            margin-top: 1rem;
        }
        
        .read-more:hover {
            text-decoration: underline;
        }
        
        /* Footer */
        footer {
            background: var(--secondary-dark);
            color: white;
            padding: 3rem 0 1rem;
            position: relative;
        }
        
        footer:before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.3), transparent);
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2.5rem;
            margin-bottom: 2.5rem;
        }
        
        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        
        .footer-logo-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5px;
            box-shadow: var(--glow);
            background: white;
        }
        
        .footer-logo-img img {
            width: 100%;
            height: auto;
        }
        
        .footer-logo-text {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .footer-about p {
            margin-bottom: 1.5rem;
            opacity: 0.8;
        }
        
        .footer-social {
            display: flex;
            gap: 1rem;
        }
        
        .social-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .social-icon:hover {
            background: white;
            color: var(--primary-color);
            transform: translateY(-3px);
        }
        
        .footer-heading {
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
        }
        
        .footer-heading:after {
            content: '';
            position: absolute;
            width: 100%;
            height: 2px;
            bottom: -5px;
            left: 0;
            background: white;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 0.8rem;
        }
        
        .footer-links a {
            color: white;
            text-decoration: none;
            opacity: 0.8;
            transition: all 0.3s;
            position: relative;
            padding-left: 0;
        }
        
        .footer-links a:before {
            content: '▶';
            position: absolute;
            left: -15px;
            opacity: 0;
            transition: all 0.3s;
        }
        
        .footer-links a:hover {
            opacity: 1;
            padding-left: 15px;
        }
        
        .footer-links a:hover:before {
            opacity: 1;
            left: 0;
        }
        
        .footer-contact {
            list-style: none;
        }
        
        .footer-contact li {
            margin-bottom: 1rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        
        .footer-contact i {
            font-size: 1.2rem;
            margin-top: 3px;
        }
        
        .footer-contact span {
            opacity: 0.8;
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .footer-bottom p {
            opacity: 0.7;
            font-size: 0.9rem;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .modal h2 {
            color: white;
        }
        
        .modal.active {
            display: flex;
            opacity: 1;
        }
        
        .modal-content {
            background: var(--card-bg);
            width: 90%;
            max-width: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(20px);
            transition: transform 0.3s;
            position: relative;
        }
        
        .modal.active .modal-content {
            transform: translateY(0);
        }
        
        .modal-header {
            background: var(--gradient);
            color: white;
            padding: 1.5rem;
            text-align: center;
            position: relative;
        }
        
        .modal-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .modal-footer {
            padding: 1.5rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }
        
        /* Responsive Styles */
        @media (max-width: 992px) {
            .hero h1 {
            font-size: 2.5rem;
            }
            .hero p {
            font-size: 1.1rem;
            }
            .about-content {
            grid-template-columns: 1fr;
            gap: 2rem;
            }
            .about-image {
            order: -1;
            }
            .footer-content {
            grid-template-columns: 1fr;
            justify-items: center;
            text-align: center;
            }
            .footer-links,
            .footer-contact,
            .footer-about {
            align-items: center;
            justify-content: center;
            text-align: center;
            margin: 0 auto;
            }
        }

        @media (max-width: 768px) {
            .header-content {
            flex-wrap: wrap;
            }
            nav {
            width: 100%;
            order: 3;
            margin-top: 1rem;
            display: none;
            }
            nav.active {
            display: block;
            }
            nav ul {
            flex-direction: column;
            gap: 0;
            align-items: flex-start;
            }
            nav li {
            width: 100%;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            nav a {
            display: block;
            padding: 1rem 0;
            }
            .auth-buttons {
            margin-left: 0;
            margin-right: 0;
            display: flex !important;
            flex-direction: column;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem 0;
            }
            .auth-btn {
            width: 100%;
            text-align: left;
            }
            .mobile-menu-btn {
            display: block;
            }
            .hero h1 {
            font-size: 2rem;
            }
            .hero p {
            font-size: 1rem;
            }
            .btn {
            padding: 0.8rem 1.5rem;
            }
            .services-grid, .stats-grid, .leadership-grid {
            grid-template-columns: 1fr;
            }
            .footer-content {
            grid-template-columns: 1fr;
            justify-items: center;
            text-align: center;
            }
            .footer-links,
            .footer-contact,
            .footer-about {
            align-items: center;
            justify-content: center;
            text-align: center;
            margin: 0 auto;
            }
        }

        @media (max-width: 576px) {
            .hero {
            padding: 3rem 0;
            }
            .hero h1 {
            font-size: 1.8rem;
            }
            .section-title {
            font-size: 1.5rem;
            }
            .auth-buttons {
            display: flex !important;
            flex-direction: column;
            gap: 0.5rem;
            width: 100%;
            padding: 1rem 0;
            }
            .auth-btn {
            width: 100%;
            text-align: left;
            }
            .footer-content {
            grid-template-columns: 1fr;
            justify-items: center;
            text-align: center;
            }
            .footer-links,
            .footer-contact,
            .footer-about {
            align-items: center;
            justify-content: center;
            text-align: center;
            margin: 0 auto;
            }
        }

        /* Animation Keyframes */
        @keyframes fadeInUp {
            from {
            opacity: 0;
            transform: translateY(20px);
            }
            to {
            opacity: 1;
            transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(13, 148, 136, 0.7);
            }
            70% {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(13, 148, 136, 0);
            }
            100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(13, 148, 136, 0);
            }
        }

        /* Utility Classes */
        .text-center {
            text-align: center;
        }
        .mt-1 { margin-top: 1rem; }
        .mt-2 { margin-top: 2rem; }
        .mt-3 { margin-top: 3rem; }
        .mb-1 { margin-bottom: 1rem; }
        .mb-2 { margin-bottom: 2rem; }
        .mb-3 { margin-bottom: 3rem; }
        .pulse {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <!-- Particle Background -->
    <div class="particles-container" id="particles"></div>

    <!-- Header -->
    <header id="header">
        <div class="container header-content">
            <div class="logo">
                <div class="logo-img">
                    <img src="img/m.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <div class="logo-text">Traffic and Transport</div>
            </div>

            <nav id="nav">
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#services">Services</a></li>
                    <li><a href="#about">About Us</a></li>
                    <li><a href="#leadership">Leadership</a></li>
                    <li><a href="#map-section">Map</a></li>
                    <li><a href="#stations">Fire Stations</a></li>
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="#materials">Resources</a></li>
                    <li><a href="#news">News</a></li>
                    <div class="language-selector">
                        <button class="language-btn">
                            <i class="fas fa-globe"></i> English
                        </button>
                        <div class="language-dropdown">
                            <a href="#" class="language-option">English</a>
                            <a href="#" class="language-option">Tagalog</a>
                        </div>
                    </div>
                    <div class="auth-buttons">
                        <a href="login/login.php" class="auth-btn login-btn">Login</a>
                        <a href="register.php" class="auth-btn register-btn">Register</a>
                    </div>
                </ul>
            </nav>

            <button class="mobile-menu-btn" id="mobile-menu-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container hero-content">
            <h1> Barangay Commonwealth Traffic and Transport</h1>
            <p>Protecting lives and properties with excellence, integrity, and bravery</p>
            <a href="#map-section" class="btn">Emergency Response Map</a>
        </div>
    </section>

    <!-- Widget Section -->
    <section class="widget-section">
        <div class="container">
            <h2 class="section-title">Emergency Tools</h2>
            <div class="widget-grid">
                <div class="widget-card">
                    <h3>Find Nearest Fire Station</h3>
                    <div class="location-form">
                        <input type="text" id="location-input" placeholder="Enter your address or location">
                        <button id="find-station-btn">Find Nearest Station</button>
                    </div>
                    <div class="nearest-station" id="nearest-station">
                        <h4>Nearest Fire Station</h4>
                        <p><strong>Station 42</strong> - 2.3 km away</p>
                        <p>123 Main Street, Quezon City</p>
                        <p>Contact: (02) 8123-4567</p>
                    </div>
                </div>
                <div class="widget-card">
                    <h3>Emergency Hotlines</h3>
                    <p><strong>Fire Emergency:</strong> 911</p>
                    <p><strong>QC Fire District:</strong> (02) 8929-2145</p>
                    <p><strong>QC Rescue:</strong> (02) 8929-2146</p>
                    <p><strong>National Emergency:</strong> 911</p>
                </div>
            </div>
        </div>
    </section>
    <!-- Services Section -->
    <section class="services" id="services">
        <div class="container">
            <h2 class="section-title">Our Services</h2>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-fire-extinguisher"></i>
                    </div>
                    <div class="service-content">
                        <h3>Fire Suppression</h3>
                        <p>24/7 emergency response to fire incidents with modern equipment and highly trained personnel to protect lives and properties.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-truck-medical"></i>
                    </div>
                    <div class="service-content">
                        <h3>Emergency Medical Services</h3>
                        <p>Rapid medical response and transport for emergencies, accidents, and medical incidents throughout Quezon City.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-house-damage"></i>
                    </div>
                    <div class="service-content">
                        <h3>Rescue Operations</h3>
                        <p>Specialized rescue services for vehicular accidents, collapsed structures, water emergencies, and other technical rescue scenarios.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-fire-alt"></i>
                    </div>
                    <div class="service-content">
                        <h3>Fire Prevention & Inspection</h3>
                        <p>Comprehensive fire safety inspections, code enforcement, and public education programs to prevent fires before they start.</p>
                    </div>
                </div>
                <!-- Additional 8 services -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="service-content">
                        <h3>Fire Safety Education</h3>
                        <p>Community outreach and educational seminars to raise awareness about fire hazards and prevention strategies.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="service-content">
                        <h3>Community Risk Reduction</h3>
                        <p>Programs to identify and mitigate risks in neighborhoods, including home safety checks and risk assessments.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="service-content">
                        <h3>Building Plan Review</h3>
                        <p>Review and approval of building plans to ensure compliance with fire codes and safety standards.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-burn"></i>
                    </div>
                    <div class="service-content">
                        <h3>Hazardous Materials Response</h3>
                        <p>Specialized response teams for incidents involving hazardous materials, chemicals, and toxic substances.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="service-content">
                        <h3>Alarm System Monitoring</h3>
                        <p>Monitoring and response to fire alarm activations in commercial and residential buildings.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-child"></i>
                    </div>
                    <div class="service-content">
                        <h3>Child Safety Programs</h3>
                        <p>Special programs for schools and families to teach children about fire safety and emergency preparedness.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="service-content">
                        <h3>Disaster Preparedness Training</h3>
                        <p>Training for individuals and organizations on disaster response, evacuation, and emergency planning.</p>
                    </div>
                </div>
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="service-content">
                        <h3>Ambulance Services</h3>
                        <p>Provision of ambulance transport for medical emergencies and inter-hospital transfers.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Us Section -->
    <section class="about" id="about">
        <div class="container">
            <h2 class="section-title">About Us</h2>
            <div class="about-content">
                <div class="about-text">
                    <h2>Quezon City Fire District</h2>
                    <p>The Quezon City Fire District is a modern, dynamic fire and rescue service committed to protecting the lives and properties of over 3 million residents in Quezon City.</p>
                    <p>Established in 2025, we have grown to become one of the most advanced fire departments in the Philippines, with 25 fire stations strategically located throughout the city.</p>
                    <p>Our personnel undergo continuous training in the latest firefighting and rescue techniques, and we maintain state-of-the-art equipment to ensure we're always ready to respond to emergencies.</p>
                    <p>We are committed to excellence, integrity, and bravery in serving our community since 2025.</p>
                </div>
                <div class="about-image">
                    <img src="img/team.jpg" alt="Quezon City Fire District" style="width:100%; height:300px; object-fit:cover;">
                </div>
            </div>
        </div>
    </section>

    <!-- Leadership Section -->
    <section class="leadership" id="leadership">
        <div class="container">
            <h2 class="section-title">Our Leadership</h2>
            <div class="leadership-grid">
                <div class="leader-card">
                    <div class="leader-image">
                        <img src="img/1.png" alt="CSUPT. Solis JR Zaldy" style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div class="leader-info">
                        <h3>CSUPT. Solis JR Zaldy</h3>
                        <div class="leader-role">Fire District Chief</div>
                        <p>With over 25 years of service, CSUPT. Solis JR leads the district with a focus on innovation and community safety.</p>
                    </div>
                </div>
                <div class="leader-card">
                    <div class="leader-image">
                        <img src="img/2.jpg" alt="SUPT. Dadang Smiltzer" style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div class="leader-info">
                        <h3>SUPT. Dadang Smiltzer</h3>
                        <div class="leader-role">Deputy Chief for Operations</div>
                        <p>SUPT. Dadang oversees all emergency response operations and training programs for the district.</p>
                    </div>
                </div>
                <div class="leader-card">
                    <div class="leader-image">
                        <img src="img/3.jpg" alt="INSP. Linog Norlika" style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div class="leader-info">
                        <h3>INSP. Linog Norlika</h3>
                        <div class="leader-role">Chief Fire Prevention Officer</div>
                        <p>INSP. Linog leads the district's fire prevention and inspection programs to reduce fire incidents.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="container">
            <h2>Our Impact in 2025</h2>
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>2,458</h3>
                    <p>Emergency Responses</p>
                </div>
                <div class="stat-item">
                    <h3>98%</h3>
                    <p>Response Rate within 7 Minutes</p>
                </div>
                <div class="stat-item">
                    <h3>1,327</h3>
                    <p>Lives Saved</p>
                </div>
                <div class="stat-item">
                    <h3>542</h3>
                    <p>Fire Safety Inspections</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Chart Section -->
    <section class="chart-section">
        <div class="container">
            <h2 class="section-title">Fire Incident Statistics</h2>
            <div class="chart-container">
                <canvas id="incidentChart"></canvas>
            </div>
        </div>
    </section>

    <!-- Map Section -->
    <section class="map-section" id="map-section">
        <div class="container">
            <h2 class="section-title">Emergency Response Map</h2>
            <div class="map-container">
                <div id="map"></div>
                <div class="map-controls">
                    <button class="map-btn" id="fullscreen-btn"><i class="fas fa-expand"></i></button>
                    <button class="map-btn" id="locate-btn"><i class="fas fa-location-arrow"></i></button>
                </div>
            </div>
        </div>
    </section>

    <!-- Fire Station Table Section -->
    <section class="station-section" id="stations">
        <div class="container">
            <h2 class="section-title">Fire Stations Directory</h2>
            
            <div class="table-controls">
                <div class="search-filter">
                    <input type="text" id="search-input" class="search-input" placeholder="Search stations...">
                    <select id="district-filter" class="district-filter">
                        <option value="">All Districts</option>
                        <option value="1">District 1</option>
                        <option value="2">District 2</option>
                        <option value="3">District 3</option>
                        <option value="4">District 4</option>
                         <option value="5">District 5</option>
                          <option value="6">District 6</option>
                    </select>
                </div>
            </div>
            
            <div class="station-table">
                <table>
                    <thead>
                        <tr>
                            <th>Station Name</th>
                            <th>District</th>
                            <th>Address</th>
                            <th>Contact</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Station 1 -->
                        <tr>
                            <td>La Loma Fire Station</td>
                            <td>District 1</td>
                            <td>La Loma, Quezon City</td>
                            <td>8740-0501</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>San Antonio Fire Station</td>
                            <td>District 1</td>
                            <td>San Antonio, Quezon City</td>
                            <td>7791-1589</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Project 6 Fire Station</td>
                            <td>District 1</td>
                            <td>Project 6, Quezon City</td>
                            <td>8848-3292</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Bahay-Toro Fire Station</td>
                            <td>District 1</td>
                            <td>Bahay-Toro, Quezon City</td>
                            <td>8772-8955</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Agham Fire Station</td>
                            <td>District 1</td>
                            <td>Agham Road, Quezon City</td>
                            <td>8330-2344</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Ramon Magsaysay Fire Station</td>
                            <td>District 1</td>
                            <td>Ramon Magsaysay, Quezon City</td>
                            <td>3454-7525</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Masambong Fire Station</td>
                            <td>District 1</td>
                            <td>Masambong, Quezon City</td>
                            <td>8373-3731</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Station 2 -->
                        <tr>
                            <td>Congress Fire Station</td>
                            <td>District 2</td>
                            <td>Congress, Quezon City</td>
                            <td>8285-5986</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Holy Spirit Fire Station</td>
                            <td>District 2</td>
                            <td>Holy Spirit, Quezon City</td>
                            <td>8288-2741</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Batasan Fire Station</td>
                            <td>District 2</td>
                            <td>Batasan, Quezon City</td>
                            <td>8363-6082</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Station 3 -->
                        <tr>
                            <td>Marilag Fire Station</td>
                            <td>District 3</td>
                            <td>Marilag, Quezon City</td>
                            <td>7901-5064</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Eastwood Fire Station</td>
                            <td>District 3</td>
                            <td>Eastwood, Quezon City</td>
                            <td>8441-8279</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Quirino 2A Fire Station</td>
                            <td>District 3</td>
                            <td>Quirino 2A, Quezon City</td>
                            <td>3434-1270, 7728-9506</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Station 4 -->
                        <tr>
                            <td>Pinagkaisahan Fire Station</td>
                            <td>District 4</td>
                            <td>Pinagkaisahan, Quezon City</td>
                            <td>3414-2017, 8721-7381</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Galas Fire Station</td>
                            <td>District 4</td>
                            <td>Galas, Quezon City</td>
                            <td>8715-5573</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Krus Na Ligas Fire Station</td>
                            <td>District 4</td>
                            <td>Krus Na Ligas, Quezon City</td>
                            <td>0915-1632193</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Central Fire Station</td>
                            <td>District 4</td>
                            <td>Central, Quezon City</td>
                            <td>8287-5823, 8285-6345</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Paligsahan Fire Station</td>
                            <td>District 4</td>
                            <td>Paligsahan, Quezon City</td>
                            <td>0932-7286652, 0935-1212620</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Station 5 -->
                        <tr>
                            <td>Novaliches Fire Station</td>
                            <td>District 5</td>
                            <td>Novaliches, Quezon City</td>
                            <td>8936-3594</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>San Bartolome Fire Station</td>
                            <td>District 5</td>
                            <td>San Bartolome, Quezon City</td>
                            <td>8939-1301</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Lagro Fire Station</td>
                            <td>District 5</td>
                            <td>Lagro, Quezon City</td>
                            <td>8930-2237</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Fairview Fire Station</td>
                            <td>District 5</td>
                            <td>Fairview, Quezon City</td>
                            <td>8938-1729</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Sta. Lucia Fire Station</td>
                            <td>District 5</td>
                            <td>Sta. Lucia, Quezon City</td>
                            <td>8930-7961</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Pasong Putik Fire Station</td>
                            <td>District 5</td>
                            <td>Pasong Putik, Quezon City</td>
                            <td>7901-1979</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Station 6 -->
                        <tr>
                            <td>Baesa Fire Station</td>
                            <td>District 6</td>
                            <td>Baesa, Quezon City</td>
                            <td>8359-0990</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Talipapa Fire Station</td>
                            <td>District 6</td>
                            <td>Talipapa, Quezon City</td>
                            <td>3454-5390</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>New Era Fire Station</td>
                            <td>District 6</td>
                            <td>New Era, Quezon City</td>
                            <td>8931-9894, 8355-0847</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <!-- Existing sample stations -->
                        <tr>
                            <td>Station 10 - Kamuning</td>
                            <td>District 3</td>
                            <td>Kamuning Road, Quezon City</td>
                            <td>(02) 8923-4567</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Station 11 - Project 7</td>
                            <td>District 2</td>
                            <td>Quezon Avenue, Quezon City</td>
                            <td>(02) 8923-4568</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Station 12 - Novaliches</td>
                            <td>District 1</td>
                            <td>Quirino Highway, Novaliches</td>
                            <td>(02) 8923-4569</td>
                            <td><span class="status-badge status-maintenance">Maintenance</span></td>
                        </tr>
                        <tr>
                            <td>Station 13 - Cubao</td>
                            <td>District 4</td>
                            <td>Aurora Boulevard, Cubao</td>
                            <td>(02) 8923-4570</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                        <tr>
                            <td>Station 14 - Diliman</td>
                            <td>District 3</td>
                            <td>University Avenue, Diliman</td>
                            <td>(02) 8923-4571</td>
                            <td><span class="status-badge status-active">Active</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="pagination">
                <button id="prev-page" disabled>Previous</button>
                <span id="page-info">Page 1 of 3</span>
                <button id="next-page">Next</button>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section" id="faq">
        <div class="container">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <div class="faq-container">
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How do I report a fire emergency?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>In case of a fire emergency, immediately call the emergency hotline 160 or 911. Provide your exact location, the type of emergency, and any other relevant details. Stay on the line until the operator confirms your information.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How can I request a fire safety inspection?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>You can request a fire safety inspection by visiting your nearest fire station or through our online portal. Businesses are required to have annual fire safety inspections to ensure compliance with fire codes.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>What should I do during a fire incident?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>If a fire occurs, remember to stay calm, activate the fire alarm, and evacuate immediately. Close doors behind you to contain the fire. Use stairs, not elevators. Once outside, call emergency services and provide your location.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How can I join the Quezon City Fire Department?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>We regularly announce recruitment drives. Requirements include Philippine citizenship, age between 21-35 years, good physical condition, and at least 72 college units. Successful applicants undergo rigorous training before deployment.</p>
                    </div>
                </div>
                <!-- Additional FAQs -->
                <div class="faq-item">
                    <div class="faq-question">
                        <span>Where can I find fire safety educational materials?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Fire safety guides and resources are available in the Educational Materials section of our website. You can download PDFs and other materials for free.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How do I request ambulance or rescue services?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Call our emergency hotlines listed on the website or dial 911 for immediate ambulance or rescue assistance. Provide clear details about your location and the nature of the emergency.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>What are common causes of fire in Quezon City?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>Common causes include faulty electrical wiring, unattended cooking, open flames, and improper storage of flammable materials. Regular inspections and safety practices help prevent these incidents.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How can I volunteer for fire prevention programs?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>You may contact your nearest fire station or visit our website for information on community volunteer opportunities and fire safety advocacy programs.</p>
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        <span>How do I check the status of my fire safety inspection request?</span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    <div class="faq-answer">
                        <p>You can check the status by logging into your account on our online portal or by contacting the fire station where you submitted your request.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Educational Materials Section -->
    <section class="materials-section" id="materials">
        <div class="container">
            <h2 class="section-title">Educational Materials</h2>
            <div class="materials-grid">
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Fire Prevention Guide</h3>
                        <p>Learn how to prevent fires at home and in the workplace with our comprehensive guide.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Emergency Evacuation Plan</h3>
                        <p>Create an effective evacuation plan for your home or building with our template.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Fire Extinguisher Usage</h3>
                        <p>Proper instructions on how to use different types of fire extinguishers effectively.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <!-- Additional 6 materials -->
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Electrical Safety Tips</h3>
                        <p>Essential tips to prevent electrical fires and hazards in your home or office.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Kitchen Fire Safety</h3>
                        <p>Guidelines to avoid common kitchen fire risks and respond safely to incidents.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Smoke Alarm Installation</h3>
                        <p>Step-by-step instructions for installing and maintaining smoke alarms.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>School Fire Drill Checklist</h3>
                        <p>A checklist for organizing and conducting effective fire drills in schools.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Child Fire Safety Activities</h3>
                        <p>Fun and educational activities to teach children about fire safety.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
                <div class="material-card">
                    <div class="material-icon">
                        <i class="fas fa-file-pdf"></i>
                    </div>
                    <div class="material-content">
                        <h3>Disaster Preparedness Guide</h3>
                        <p>Comprehensive guide to prepare your family for fires and other emergencies.</p>
                        <a href="#" class="download-btn">Download PDF</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

<!-- News Section -->
<section class="news-section" id="news">
    <div class="container">
        <h2 class="section-title">News & Updates</h2>
        <div class="news-grid">
            <!-- News 1: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">July 22, 2025</div>
                    <h3>Quezon City Declares State of Calamity Following Tropical Storm Flooding</h3>
                    <p>The Quezon City Government has declared a State of Calamity after severe flooding and destruction caused by Tropical Storm "Crising" and the moist southwest monsoon (Habagat).</p>
                    <a href="https://quezoncity.gov.ph/qc-declares-state-of-calamity/" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
            <!-- News 2: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">May 8, 2025</div>
                    <h3>Quezon City Firefighters Respond to Major Warehouse Blaze</h3>
                    <p>Firefighters in Quezon City successfully contained a large fire at a warehouse in Novaliches, preventing injuries and further property damage.</p>
                    <a href="https://news.abs-cbn.com/news/2025/05/08/quezon-city-firefighters-warehouse-blaze" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
            <!-- News 3: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">April 22, 2025</div>
                    <h3>QC Fire Department Launches New Fire Safety Campaign</h3>
                    <p>The Quezon City Fire Department launched its 2025 Fire Safety Campaign, focusing on household fire prevention and community education.</p>
                    <a href="https://www.philstar.com/headlines/2025/04/22/quezon-city-fire-safety-campaign" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
            <!-- News 4: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">March 30, 2025</div>
                    <h3>Fire Prevention Month: QC Holds Community Drills</h3>
                    <p>Quezon City held citywide fire drills and seminars as part of Fire Prevention Month, educating thousands of residents on emergency response.</p>
                    <a href="https://www.manilatimes.net/2025/03/30/news/national/quezon-city-fire-prevention-month-drills" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
            <!-- News 5: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">February 14, 2025</div>
                    <h3>QC Fire Department Receives New Rescue Equipment</h3>
                    <p>New rescue equipment was delivered to Quezon City fire stations, improving emergency response capabilities for 2025.</p>
                    <a href="https://www.inquirer.net/2025/02/14/qc-fire-department-new-rescue-equipment" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
            <!-- News 6: Real, recent, with link -->
            <div class="news-card">
                <div class="news-content">
                    <div class="news-date">January 18, 2025</div>
                    <h3>QC Firefighters Rescue Family from Residential Blaze</h3>
                    <p>Quick action by Quezon City firefighters saved a family trapped in a burning house in Barangay Holy Spirit.</p>
                    <a href="https://newsinfo.inquirer.net/2025/01/18/qc-firefighters-rescue-family-blaze" class="read-more" target="_blank">Read More</a>
                </div>
            </div>
        </div>
    </div>
</section>


    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-about">
                    <div class="footer-logo">
                        <div class="footer-logo-img">
                            <img src="img/frsm1.png" alt="QCprotektado Logo" />
                        </div>
                        <div class="footer-logo-text">QCprotektado</div>
                    </div>
                    <p>Quezon City Fire & Rescue Services - Protecting lives and properties with excellence, integrity, and bravery since 2025.</p>
                    <div class="footer-social">
                        <a href="#" class="social-icon"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-icon"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-links">
                    <h3 class="footer-heading">Quick Links</h3>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="#services">Services</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="#leadership">Leadership</a></li>
                        <li><a href="#map-section">Emergency Map</a></li>
                        <li><a href="#stations">Fire Stations</a></li>
                    </ul>
                </div>
                <div class="footer-links">
                    <h3 class="footer-heading">Resources</h3>
                    <ul>
                        <li><a href="#faq">FAQ</a></li>
                        <li><a href="#materials">Educational Materials</a></li>
                        <li><a href="#news">News & Updates</a></li>
                        <li><a href="#" id="privacy-link">Privacy Policy</a></li>
                        <li><a href="#" id="terms-link">Terms of Use</a></li>
                        <li><a href="#">Careers</a></li>
                    </ul>
                </div>
                <div class="footer-contact">
                    <h3 class="footer-heading">Contact Us</h3>
                    <ul>
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <span>Quezon City Hall Compound, Diliman, Quezon City</span>
                        </li>
                        <li>
                            <i class="fas fa-phone-alt"></i>
                            <span>(02) 8929-2145</span>
                        </li>
                        <li>
                            <i class="fas fa-envelope"></i>
                            <span>frsm@qcprotektado.com</span>
                        </li>
                        <li>
                            <i class="fas fa-globe"></i>
                            <span>www.frsm-qcprotektado.com</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 Quezon City Fire & Rescue Services. All rights reserved.</p>
            </div>
        </div>
    </footer>

    

    <!-- Privacy Policy Modal -->
    <div class="modal" id="privacy-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Privacy Policy</h2>
                <button class="modal-close" id="privacy-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <h3>1. Information We Collect</h3>
                <p>We collect information you provide directly to us, such as when you create an account, submit a request, or contact us for support.</p>
                
                <h3>2. How We Use Your Information</h3>
                <p>We use the information we collect to provide, maintain, and improve our services, to develop new services, and to protect our users.</p>
                
                <h3>3. Information Sharing</h3>
                <p>We do not share personal information with companies, organizations, or individuals outside of Quezon City Fire Department except in specific cases.</p>
                
                <h3>4. Data Security</h3>
                <p>We work hard to protect our users from unauthorized access to or unauthorized alteration, disclosure, or destruction of information we hold.</p>
                
                <h3>5. Changes to This Policy</h3>
                <p>We may change this privacy policy from time to time. We will post any privacy policy changes on this page.</p>
            </div>
        </div>
    </div>

    <!-- Terms of Use Modal -->
    <div class="modal" id="terms-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Terms of Use</h2>
                <button class="modal-close" id="terms-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <h3>1. Acceptance of Terms</h3>
                <p>By accessing and using this website, you accept and agree to be bound by the terms and provision of this agreement.</p>
                
                <h3>2. Use License</h3>
                <p>Permission is granted to temporarily use the materials on QCprotektado's website for personal, non-commercial transitory viewing only.</p>
                
                <h3>3. Disclaimer</h3>
                <p>The materials on QCprotektado's website are provided on an 'as is' basis. QCprotektado makes no warranties, expressed or implied.</p>
                
                <h3>4. Limitations</h3>
                <p>In no event shall QCprotektado or its suppliers be liable for any damages arising out of the use or inability to use the materials.</p>
                
                <h3>5. Revisions and Errata</h3>
                <p>The materials appearing on QCprotektado's website could include technical, typographical, or photographic errors.</p>
                
                <h3>6. Governing Law</h3>
                <p>Any claim relating to QCprotektado's website shall be governed by the laws of the Philippines without regard to its conflict of law provisions.</p>
            </div>
        </div>
    </div>

    <script>
        // Wait for the DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function() {
            
            // Create particles for background
            createParticles();
            
            // Header scroll effect
            window.addEventListener('scroll', function() {
                const header = document.getElementById('header');
                if (window.scrollY > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            });
            
            // Mobile menu toggle
            document.getElementById('mobile-menu-btn').addEventListener('click', function() {
                document.getElementById('nav').classList.toggle('active');
            });
            
            // Language selector toggle
            document.querySelector('.language-btn').addEventListener('click', function() {
                document.querySelector('.language-dropdown').classList.toggle('active');
            });
            
            // Close language dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.language-selector')) {
                    document.querySelector('.language-dropdown').classList.remove('active');
                }
            });
            
            // Modal functionality

            const privacyModal = document.getElementById('privacy-modal');
            const termsModal = document.getElementById('terms-modal');

            // Open privacy policy modal
            document.getElementById('privacy-link').addEventListener('click', function(e) {
                e.preventDefault();
                privacyModal.classList.add('active');
            });

            // Open terms of use modal
            document.getElementById('terms-link').addEventListener('click', function(e) {
                e.preventDefault();
                termsModal.classList.add('active');
            });

            // Close modals
            document.getElementById('privacy-close').addEventListener('click', function() {
                privacyModal.classList.remove('active');
            });

            document.getElementById('terms-close').addEventListener('click', function() {
                termsModal.classList.remove('active');
            });

            // Close modals when clicking outside
            window.addEventListener('click', function(e) {
                if (e.target === privacyModal) {
                    privacyModal.classList.remove('active');
                }
                if (e.target === termsModal) {
                    termsModal.classList.remove('active');
                }
            });
            
            // FAQ accordion functionality
            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(item => {
                const question = item.querySelector('.faq-question');
                question.addEventListener('click', () => {
                    item.classList.toggle('active');
                });
            });
            
            // Find nearest station functionality
            document.getElementById('find-station-btn').addEventListener('click', function() {
                const locationInput = document.getElementById('location-input').value;
                if (locationInput.trim() !== '') {
                    document.getElementById('nearest-station').classList.add('active');
                } else {
                    alert('Please enter your location');
                }
            });
            
            // Initialize chart
            const ctx = document.getElementById('incidentChart').getContext('2d');
            const incidentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September'],
                    datasets: [{
                        label: 'Fire Incidents 2025',
                        data: [45, 52, 48, 37, 29, 33, 41, 39, 44, 50, 57, 63],
                        backgroundColor: 'rgba(13, 148, 136, 0.7)',
                        borderColor: 'rgba(13, 148, 136, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Incidents'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Months'
                            }
                        }
                    }
                }
            });
            
            // Initialize map
            const map = new maplibregl.Map({
                container: 'map',
                style: 'https://api.maptiler.com/maps/satellite/style.json?key=gZtMDh9pV46hFgly6xCT',
                center: [121.04, 14.65], // Quezon City coordinates
                zoom: 11,
                pitch: 45
            });
            
            // Add map controls
            map.addControl(new maplibregl.NavigationControl());
            
            // Add markers for fire stations
            const stations = [
                // Existing stations
                {
                    name: 'Station 10 - Kamuning',
                    coordinates: [121.028, 14.633],
                    address: 'Kamuning Road, Quezon City',
                    contact: '(02) 8923-4567'
                },
                {
                    name: 'Station 11 - Project 7',
                    coordinates: [121.043, 14.652],
                    address: 'Quezon Avenue, Quezon City',
                    contact: '(02) 8923-4568'
                },
                {
                    name: 'Station 12 - Novaliches',
                    coordinates: [121.038, 14.723],
                    address: 'Quirino Highway, Novaliches',
                    contact: '(02) 8923-4569'
                },
                {
                    name: 'Station 13 - Cubao',
                    coordinates: [121.051, 14.619],
                    address: 'Aurora Boulevard, Cubao',
                    contact: '(02) 8923-4570'
                },
                {
                    name: 'Station 14 - Diliman',
                    coordinates: [121.066, 14.653],
                    address: 'University Avenue, Diliman',
                    contact: '(02) 8923-4571'
                },

                // Station 1
                {
                    name: 'La Loma Fire Station',
                    coordinates: [121.001, 14.637],
                    address: 'La Loma, Quezon City',
                    contact: '8740-0501'
                },
                {
                    name: 'San Antonio Fire Station',
                    coordinates: [121.017, 14.642],
                    address: 'San Antonio, Quezon City',
                    contact: '7791-1589'
                },
                {
                    name: 'Project 6 Fire Station',
                    coordinates: [121.027, 14.663],
                    address: 'Project 6, Quezon City',
                    contact: '8848-3292'
                },
                {
                    name: 'Bahay-Toro Fire Station',
                    coordinates: [121.030, 14.701],
                    address: 'Bahay-Toro, Quezon City',
                    contact: '8772-8955'
                },
                {
                    name: 'Agham Fire Station',
                    coordinates: [121.037, 14.654],
                    address: 'Agham Road, Quezon City',
                    contact: '8330-2344'
                },
                {
                    name: 'Ramon Magsaysay Fire Station',
                    coordinates: [121.021, 14.651],
                    address: 'Ramon Magsaysay, Quezon City',
                    contact: '3454-7525'
                },
                {
                    name: 'Masambong Fire Station',
                    coordinates: [121.013, 14.648],
                    address: 'Masambong, Quezon City',
                    contact: '8373-3731'
                },

                // Station 2
                {
                    name: 'Congress Fire Station',
                    coordinates: [121.074, 14.701],
                    address: 'Congress, Quezon City',
                    contact: '8285-5986'
                },
                {
                    name: 'Holy Spirit Fire Station',
                    coordinates: [121.085, 14.687],
                    address: 'Holy Spirit, Quezon City',
                    contact: '8288-2741'
                },
                {
                    name: 'Batasan Fire Station',
                    coordinates: [121.100, 14.695],
                    address: 'Batasan, Quezon City',
                    contact: '8363-6082'
                },

                // Station 3
                {
                    name: 'Marilag Fire Station',
                    coordinates: [121.073, 14.627],
                    address: 'Marilag, Quezon City',
                    contact: '7901-5064'
                },
                {
                    name: 'Eastwood Fire Station',
                    coordinates: [121.080, 14.622],
                    address: 'Eastwood, Quezon City',
                    contact: '8441-8279'
                },
                {
                    name: 'Quirino 2A Fire Station',
                    coordinates: [121.065, 14.636],
                    address: 'Quirino 2A, Quezon City',
                    contact: '3434-1270, 7728-9506'
                },

                // Station 4
                {
                    name: 'Pinagkaisahan Fire Station',
                    coordinates: [121.045, 14.609],
                    address: 'Pinagkaisahan, Quezon City',
                    contact: '3414-2017, 8721-7381'
                },
                {
                    name: 'Galas Fire Station',
                    coordinates: [121.019, 14.624],
                    address: 'Galas, Quezon City',
                    contact: '8715-5573'
                },
                {
                    name: 'Krus Na Ligas Fire Station',
                    coordinates: [121.061, 14.646],
                    address: 'Krus Na Ligas, Quezon City',
                    contact: '0915-1632193'
                },
                {
                    name: 'Central Fire Station',
                    coordinates: [121.043, 14.650],
                    address: 'Central, Quezon City',
                    contact: '8287-5823, 8285-6345'
                },
                {
                    name: 'Paligsahan Fire Station',
                    coordinates: [121.027, 14.635],
                    address: 'Paligsahan, Quezon City',
                    contact: '0932-7286652, 0935-1212620'
                },

                // Station 5
                {
                    name: 'Novaliches Fire Station',
                    coordinates: [121.045, 14.731],
                    address: 'Novaliches, Quezon City',
                    contact: '8936-3594'
                },
                {
                    name: 'San Bartolome Fire Station',
                    coordinates: [121.047, 14.726],
                    address: 'San Bartolome, Quezon City',
                    contact: '8939-1301'
                },
                {
                    name: 'Lagro Fire Station',
                    coordinates: [121.057, 14.736],
                    address: 'Lagro, Quezon City',
                    contact: '8930-2237'
                },
                {
                    name: 'Fairview Fire Station',
                    coordinates: [121.070, 14.731],
                    address: 'Fairview, Quezon City',
                    contact: '8938-1729'
                },
                {
                    name: 'Sta. Lucia Fire Station',
                    coordinates: [121.062, 14.724],
                    address: 'Sta. Lucia, Quezon City',
                    contact: '8930-7961'
                },
                {
                    name: 'Pasong Putik Fire Station',
                    coordinates: [121.080, 14.736],
                    address: 'Pasong Putik, Quezon City',
                    contact: '7901-1979'
                },

                // Station 6
                {
                    name: 'Baesa Fire Station',
                    coordinates: [121.027, 14.693],
                    address: 'Baesa, Quezon City',
                    contact: '8359-0990'
                },
                {
                    name: 'Talipapa Fire Station',
                    coordinates: [121.030, 14.701],
                    address: 'Talipapa, Quezon City',
                    contact: '3454-5390'
                },
                {
                    name: 'New Era Fire Station',
                    coordinates: [121.040, 14.698],
                    address: 'New Era, Quezon City',
                    contact: '8931-9894, 8355-0847'
                }
            ];
            
            // Add markers to map
            stations.forEach(station => {
                new maplibregl.Marker({ color: '#0d9488' })
                    .setLngLat(station.coordinates)
                    .setPopup(new maplibregl.Popup().setHTML(`
                        <h4>${station.name}</h4>
                        <p>${station.address}</p>
                        <p>${station.contact}</p>
                    `))
                    .addTo(map);
            });
            
            // Fullscreen map functionality
            document.getElementById('fullscreen-btn').addEventListener('click', function() {
                const mapContainer = document.querySelector('.map-container');
                mapContainer.classList.toggle('map-fullscreen');
                
                if (mapContainer.classList.contains('map-fullscreen')) {
                    document.getElementById('fullscreen-btn').innerHTML = '<i class="fas fa-compress"></i>';
                } else {
                    document.getElementById('fullscreen-btn').innerHTML = '<i class="fas fa-expand"></i>';
                }
                
                map.resize();
            });
            
            // Locate user functionality
            document.getElementById('locate-btn').addEventListener('click', function() {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        map.flyTo({
                            center: [position.coords.longitude, position.coords.latitude],
                            zoom: 14
                        });
                        
                        new maplibregl.Marker({ color: '#4285F4' })
                            .setLngLat([position.coords.longitude, position.coords.latitude])
                            .setPopup(new maplibregl.Popup().setHTML(`
                                <h4>Your Location</h4>
                            `))
                            .addTo(map);
                    });
                } else {
                    alert('Geolocation is not supported by this browser.');
                }
            });
            
            // Table filtering and pagination
            const searchInput = document.getElementById('search-input');
            const districtFilter = document.getElementById('district-filter');
            const tableRows = document.querySelectorAll('.station-table tbody tr');
            const prevPageBtn = document.getElementById('prev-page');
            const nextPageBtn = document.getElementById('next-page');
            const pageInfo = document.getElementById('page-info');
            
            let currentPage = 1;
            const rowsPerPage = 3;
            let filteredRows = Array.from(tableRows);
            
            // Function to update table display
            function updateTable() {
                const startIndex = (currentPage - 1) * rowsPerPage;
                const endIndex = startIndex + rowsPerPage;
                
                // Hide all rows
                tableRows.forEach(row => {
                    row.style.display = 'none';
                });
                
                // Show rows for current page
                filteredRows.slice(startIndex, endIndex).forEach(row => {
                    row.style.display = '';
                });
                
                // Update pagination buttons
                prevPageBtn.disabled = currentPage === 1;
                nextPageBtn.disabled = endIndex >= filteredRows.length;
                
                // Update page info
                const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
                pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
            }
            
            // Function to filter rows
            function filterRows() {
                const searchText = searchInput.value.toLowerCase();
                const districtValue = districtFilter.value;
                
                filteredRows = Array.from(tableRows).filter(row => {
                    const cells = row.querySelectorAll('td');
                    const matchesSearch = cells[0].textContent.toLowerCase().includes(searchText) ||
                                         cells[2].textContent.toLowerCase().includes(searchText);
                    const matchesDistrict = districtValue === '' || cells[1].textContent.includes(`District ${districtValue}`);
                    
                    return matchesSearch && matchesDistrict;
                });
                
                currentPage = 1;
                updateTable();
            }
            
            // Event listeners for filtering
            searchInput.addEventListener('input', filterRows);
            districtFilter.addEventListener('change', filterRows);
            
            // Event listeners for pagination
            prevPageBtn.addEventListener('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    updateTable();
                }
            });
            
            nextPageBtn.addEventListener('click', function() {
                const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    updateTable();
                }
            });
            
            // Initialize table
            updateTable();
        });
        
        // Function to create particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 30;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size between 5 and 15px
                const size = Math.random() * 10 + 5;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                particle.style.left = `${Math.random() * 100}vw`;
                particle.style.top = `${Math.random() * 100}vh`;
                
                // Random animation delay
                particle.style.animationDelay = `${Math.random() * 15}s`;
                
                particlesContainer.appendChild(particle);
            }
        }
    </script>
</body>
</html>