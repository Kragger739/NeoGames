# Two stages: build the Vite bundle with Node, then serve the static output
# with nginx. Vite inlines every VITE_* value at BUILD time (compose passes
# them as --build-args from the root .env - see docker-compose.yml).
#
# For the single-origin deployment all of these are empty except the Reverb
# app key: the SPA then uses relative API URLs and derives the websocket
# host from window.location (see frontend/src/lib/api.ts + lib/echo.ts).
FROM node:22-alpine AS build
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

ARG VITE_API_URL=""
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT=""
ARG VITE_REVERB_SCHEME=""
ENV VITE_API_URL=$VITE_API_URL \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
