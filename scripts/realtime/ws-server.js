/* eslint-disable no-console */
const http = require("http");
const { Server } = require("socket.io");
const Redis = require("ioredis");

const DEFAULT_PORT = Number(process.env.WS_PORT || 8081);
const DEFAULT_REDIS_URL = process.env.REDIS_URL || "redis://127.0.0.1:6379";
const DEFAULT_EVENTS_CHANNEL = process.env.REDIS_EVENTS_CHANNEL || "taxi:events";

function dispatchEvent(io, event) {
  const rideRoom = event.room ? String(event.room) : null;
  const eventName = event.event || "taxi_event";

  if (rideRoom) {
    io.to(rideRoom).emit(eventName, event);
  }
  if (event.driver_id) {
    io.to(`driver:${event.driver_id}`).emit(eventName, event);
  }
  if (event.customer_id) {
    io.to(`customer:${event.customer_id}`).emit(eventName, event);
  }
  io.emit("taxi_event", event);
}

function startRealtimeServer({
  port = DEFAULT_PORT,
  redisUrl = DEFAULT_REDIS_URL,
  eventsChannel = DEFAULT_EVENTS_CHANNEL
} = {}) {
  const server = http.createServer();
  const io = new Server(server, {
    pingInterval: 25000,
    pingTimeout: 20000,
    cors: { origin: "*" }
  });

  const pubSub = new Redis(redisUrl, {
    retryStrategy(times) {
      return Math.min(times * 250, 3000);
    },
    maxRetriesPerRequest: null
  });

  io.on("connection", (socket) => {
    socket.on("disconnect", () => {
      // Socket.IO handles reconnect on client side; server keeps no sticky session state.
    });
    socket.on("join_ride", ({ ride_id }) => {
      if (!ride_id) return;
      socket.join(String(ride_id));
    });
    socket.on("join_driver", ({ driver_id }) => {
      if (!driver_id) return;
      socket.join(`driver:${driver_id}`);
    });
    socket.on("join_customer", ({ customer_id }) => {
      if (!customer_id) return;
      socket.join(`customer:${customer_id}`);
    });
  });

  pubSub.subscribe(eventsChannel, (err) => {
    if (err) {
      console.error("Redis subscribe failed", err);
      process.exit(1);
    }
    console.log(`Subscribed to ${eventsChannel}`);
  });

  pubSub.on("message", (_channel, raw) => {
    try {
      const event = JSON.parse(raw);
      dispatchEvent(io, event);
    } catch (e) {
      console.error("Invalid event payload", e);
    }
  });

  pubSub.on("error", (err) => {
    console.error("Redis pub/sub error", err);
  });

  server.listen(port, () => {
    console.log(`WS server listening on ${port}`);
  });

  return { server, io, pubSub };
}

module.exports = {
  dispatchEvent,
  startRealtimeServer
};

if (require.main === module) {
  startRealtimeServer();
}
