const { dispatchEvent } = require("../ws-server");

describe("dispatchEvent", () => {
  test("emits to ride room, driver room, customer room, and global", () => {
    const roomEmit = jest.fn();
    const io = {
      to: jest.fn(() => ({ emit: roomEmit })),
      emit: jest.fn()
    };

    const event = {
      event: "ride_accepted",
      room: "ride-123",
      driver_id: "drv-1",
      customer_id: "cus-2"
    };

    dispatchEvent(io, event);

    expect(io.to).toHaveBeenCalledWith("ride-123");
    expect(io.to).toHaveBeenCalledWith("driver:drv-1");
    expect(io.to).toHaveBeenCalledWith("customer:cus-2");
    expect(roomEmit).toHaveBeenCalledWith("ride_accepted", event);
    expect(io.emit).toHaveBeenCalledWith("taxi_event", event);
  });

  test("uses taxi_event as default event name", () => {
    const roomEmit = jest.fn();
    const io = {
      to: jest.fn(() => ({ emit: roomEmit })),
      emit: jest.fn()
    };
    const event = { room: "r1" };

    dispatchEvent(io, event);
    expect(roomEmit).toHaveBeenCalledWith("taxi_event", event);
    expect(io.emit).toHaveBeenCalledWith("taxi_event", event);
  });
});

